<?php
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) die();
return [
  'brand' => 'Container Flow', 'base_url' => 'http://127.0.0.1:' . (getenv('RB_E2E_PORT') ?: 8765),
  'mailer' => function (array $to, $subject, $html, array $att) {
      file_put_contents($GLOBALS['rb_e2e_work'] . '/sent.log', json_encode(['to' => $to, 'subject' => $subject]) . "\n", FILE_APPEND);
      return ['success' => true, 'message' => 'Sent via e2e'];
  },
];
