-- Seed data for first install.
-- Idempotent: every INSERT uses INSERT IGNORE.

INSERT IGNORE INTO `eg_settings` (`key_name`, `value`) VALUES
  ('site.tagline.en',  'Ethiopia''s Marketplace'),
  ('site.tagline.am',  'የኢትዮጵያ ገበያ'),
  ('site.commission',  '10'),
  ('site.currency',    'ETB'),
  ('site.cod_enabled', '1'),
  ('site.telebirr_enabled', '0'),
  ('site.cbe_enabled',     '0'),
  ('site.chapa_enabled',   '1'),
  ('mail.support',     'support@etelgebeya.local'),
  ('maintenance.mode', '0');

-- Top-level categories (bilingual)
INSERT IGNORE INTO `eg_categories` (`id`, `parent_id`, `name_en`, `name_am`, `slug`, `icon`, `sort_order`) VALUES
  (1, NULL, 'Electronics',     'ኤሌክትሮኒክስ',     'electronics',     'bi-laptop',         1),
  (2, NULL, 'Fashion',         'ፋሽን',              'fashion',         'bi-bag-heart',      2),
  (3, NULL, 'Home & Living',   'ቤት እና መኖሪያ',      'home-living',     'bi-house-heart',    3),
  (4, NULL, 'Beauty',          'ውበት',              'beauty',          'bi-droplet-half',   4),
  (5, NULL, 'Groceries',       'ምግብ እና መጠጥ',     'groceries',       'bi-basket3',        5),
  (6, NULL, 'Automotive',      'ተሽከርካሪ',         'automotive',      'bi-truck',          6),
  (7, NULL, 'Sports',          'ስፖርት',             'sports',          'bi-trophy',         7),
  (8, NULL, 'Books',           'መጽሐፍት',            'books',           'bi-book',           8),
  (9, NULL, 'Agriculture',     'ግብርና',             'agriculture',     'bi-flower1',        9),
  (10,NULL, 'Services',        'አገልግሎቶች',         'services',        'bi-tools',         10);

-- Subcategories (a few examples)
INSERT IGNORE INTO `eg_categories` (`id`, `parent_id`, `name_en`, `name_am`, `slug`, `icon`, `sort_order`) VALUES
  (101, 1, 'Phones',          'ስልኮች',     'electronics-phones',     'bi-phone',         1),
  (102, 1, 'Laptops',         'ላፕቶፖች',   'electronics-laptops',    'bi-laptop',        2),
  (103, 1, 'Accessories',     'ተደራጅቶች', 'electronics-accessories','bi-headphones',    3),
  (201, 2, 'Men',             'ወንዶች',     'fashion-men',            'bi-person',        1),
  (202, 2, 'Women',           'ሴቶች',      'fashion-women',          'bi-person-dress',  2),
  (203, 2, 'Kids',            'ልጆች',      'fashion-kids',           'bi-emoji-smile',   3),
  (501, 5, 'Coffee & Tea',    'ቡና እና ሻይ','groceries-coffee',       'bi-cup-hot',       1),
  (502, 5, 'Spices',          'ቅመማ ቅመም','groceries-spices',       'bi-flower2',       2),
  (503, 5, 'Oils & Grains',   'ዘይት እና እህል','groceries-oils',      'bi-droplet',       3);

-- Default admin (password: Admin@12345)
INSERT IGNORE INTO `eg_users` (`id`, `full_name`, `email`, `phone`, `password_hash`, `locale`, `status`) VALUES
  (1, 'Site Admin', 'admin@etelgebeya.local', '+251900000001',
   '$2y$12$0bX/qRu5lZc.i5Z5Z5Z5Zuexample.hash.replace.in.production.REPLACEME',
   'en', 'active');

-- The hash above is a placeholder. The installer will replace it with a real bcrypt.
-- For dev seed, we re-insert the admin password hash via a separate step in InstallController.
