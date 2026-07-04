---
name: members-site-php-version
description: members-site runs PHP 8.3 in production; lint with php8.3+ not 8.0
metadata: 
  node_type: memory
  type: reference
  originSessionId: bcfd8d63-cce8-4360-9818-5d07d45dc427
---

members-site production (Hostinger) runs **PHP 8.3**. The code uses standalone
literal union return types like `string|true` (e.g. `validateProofUpload()`,
`bookSlot()`), which are only valid in **PHP 8.2+**.

**How to apply:** lint local files with `C:\wamp64\bin\php\php8.3.28\php.exe -l`
(8.4/8.5 also available). The default `php8.0.30` FALSELY reports
"Cannot use 'true' as class name" on valid production code — don't trust it for
this project. See [[members-site-host-blocks-page-names]].
