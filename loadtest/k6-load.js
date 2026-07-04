// ============================================================
//  members-site load test (k6)  —  https://k6.io
//
//  Measures how many concurrent visitors your Hostinger Premium plan can
//  serve before response time spikes / errors start. Read-only: it only GETs
//  public pages, so it creates no accounts and triggers no emails.
//
//  Run:
//    # install k6 first (https://k6.io/docs/get-started/installation/)
//    k6 run loadtest/k6-load.js
//
//    # point at production and override the peak load:
//    k6 run -e BASE_URL=https://members.zachalcasid.com -e PEAK=100 loadtest/k6-load.js
//
//  What to watch in the summary:
//    • http_req_duration ....... p95 should stay well under 1s. When it climbs
//                                past a few seconds, you've found the ceiling.
//    • http_req_failed ......... share of non-200 responses. A sudden rise means
//                                the server started rejecting (resource limit).
//    • The VU level where those two turn bad ≈ your safe concurrent-user max.
// ============================================================

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'https://members.zachalcasid.com';
const PEAK = parseInt(__ENV.PEAK || '50', 10); // peak concurrent virtual users

// Extra failure signal (non-2xx/3xx responses).
const errors = new Rate('page_errors');

export const options = {
  // Ramp up, hold at peak, then back down. Bump PEAK to push harder.
  stages: [
    { duration: '30s', target: Math.ceil(PEAK * 0.2) }, // warm up
    { duration: '1m',  target: Math.ceil(PEAK * 0.5) },
    { duration: '2m',  target: PEAK },                   // sustained peak
    { duration: '1m',  target: PEAK },                   // hold to expose queuing
    { duration: '30s', target: 0 },                      // ramp down
  ],
  thresholds: {
    // Test "passes" while these hold. When they break, you're over capacity.
    http_req_duration: ['p(95)<1500'], // 95% of requests under 1.5s
    http_req_failed:   ['rate<0.02'],  // under 2% errors
    page_errors:       ['rate<0.02'],
  },
};

// Public, read-only pages a logged-out visitor would hit.
const PAGES = [
  '/',
  '/login.php',
  '/register.php',
  '/membership-signup.php',
  '/student-signup.php',
];

export default function () {
  group('browse public pages', () => {
    for (const path of PAGES) {
      const res = http.get(`${BASE}${path}`, {
        headers: { 'User-Agent': 'k6-loadtest members-site' },
        redirects: 0, // don't follow login redirects; we're measuring the hit
      });
      const ok = check(res, {
        'status is 200/302': (r) => r.status === 200 || r.status === 302,
      });
      errors.add(!ok);
      sleep(Math.random() * 2 + 1); // 1–3s "think time" between clicks
    }
  });
}
