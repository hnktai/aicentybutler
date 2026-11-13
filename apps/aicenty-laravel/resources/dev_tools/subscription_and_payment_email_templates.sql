INSERT INTO `email_templates` (`title`, `subject`, `content`, `created_at`, `updated_at`, `slug`) VALUES
(
  '??ng k? th?nh c?ng',
  '??ng k? th?nh c?ng',
  '<div style="padding: 0 19px">\r\n    <h1>Xin ch?o, {user_name}!</h1>\r\n    <h2>B?n ?? ??ng k? th?nh c?ng {site_name}!</h2>\r\n\r\n    <p>C?m ?n b?n ?? ??ng k? g?i {plan_name}. G?i c?a b?n hi?n ?? ???c k?ch ho?t.</p>\r\n    <p>C?m ?n b?n ?? ch?n {site_name}.</p>\r\n    <p>Nh?n <a href="{login_url}">v?o ??y</a> ?? ??ng nh?p t?i kho?n c?a b?n.</p>\r\n    <p>Nh?n <a href="{site_url}">v?o ??y</a> ?? truy c?p website.</p>\r\n</div>\r\n\r\n<br>\r\n\r\n<a href="{login_url}" class="btn btn-lg btn-block btn-round">\r\n    ??ng nh?p ngay\r\n</a>\r\n\r\n<p class="need-help-p">C?n h? tr?? <a href="{site_url}">Li?n h? v?i ch?ng t?i.</a></p>',
  NULL,
  NULL,
  'subscription-successful'
),
(
  'Thanh to?n th?nh c?ng',
  'Thanh to?n th?nh c?ng',
  '<div style="padding: 0 19px">\r\n    <h1>Xin ch?o, {user_name}!</h1>\r\n    <h2>Thanh to?n c?a b?n ?? th?nh c?ng!</h2>\r\n\r\n    <p>C?m ?n b?n. Thanh to?n cho g?i {plan_name} ?? ???c x? l? th?nh c?ng.</p>\r\n    <p>C?m ?n b?n ?? ch?n {site_name}.</p>\r\n    <p>Nh?n <a href="{login_url}">v?o ??y</a> ?? ??ng nh?p t?i kho?n c?a b?n.</p>\r\n    <p>Nh?n <a href="{site_url}">v?o ??y</a> ?? truy c?p website.</p>\r\n</div>\r\n\r\n<br>\r\n\r\n<a href="{login_url}" class="btn btn-lg btn-block btn-round">\r\n    ??ng nh?p ngay\r\n</a>\r\n\r\n<p class="need-help-p">C?n h? tr?? <a href="{site_url}">Li?n h? v?i ch?ng t?i.</a></p>',
  NULL,
  NULL,
  'payment-successful'
);
