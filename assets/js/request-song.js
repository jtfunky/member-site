// Auto-fill the (optional) title field from the video's own title via
// YouTube's public oEmbed endpoint — no API key needed. Only fills if the
// student hasn't already typed something, so it never clobbers a manual edit.
(function () {
  const urlField   = document.getElementById('youtube_url');
  const titleField = document.getElementById('title');
  const hint       = document.getElementById('title-autofill-hint');
  if (!urlField || !titleField) return;
  let lastFetched = '';

  urlField.addEventListener('blur', async () => {
    const url = urlField.value.trim();
    if (!url || url === lastFetched || titleField.value.trim()) return;
    lastFetched = url;
    try {
      const res = await fetch('https://www.youtube.com/oembed?format=json&url=' + encodeURIComponent(url));
      if (!res.ok) return;
      const data = await res.json();
      if (data.title && !titleField.value.trim()) {
        titleField.value = data.title;
        if (hint) hint.style.display = 'inline';
      }
    } catch (e) { /* not a valid/reachable video — leave title blank, admin can fill it in */ }
  });
})();
