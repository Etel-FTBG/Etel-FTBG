-- Migration 067: shared menu catalog.
-- Shops used to type every dish from scratch. The catalog is one master list of common dishes
-- (admin-managed, grown by shop suggestions); a shop picks dishes and sets only its own price.
-- Picks become normal rows in items; items.catalog_id remembers the source, quality/portion are per shop.
-- The seed list below is a starting set - admin can edit, add and hide dishes in Admin -> Menu catalog.
-- api/lib/menu_catalog.php also creates these on first use, so code and migration deploy in either order.
--
-- NOTE ON FORMATTING: every comment on its own line, every statement-terminating
-- semicolon last on its line (api/migrate.php splits by line).

CREATE TABLE IF NOT EXISTS `menu_catalog` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(150) NOT NULL, `name_am` VARCHAR(150) NULL, `description` TEXT NULL, `description_am` TEXT NULL, `category` VARCHAR(20) NOT NULL DEFAULT 'food', `cuisine` VARCHAR(40) NULL, `packs` VARCHAR(160) NULL, `image_url` VARCHAR(500) NULL, `prep_time` INT NOT NULL DEFAULT 20, `status` VARCHAR(10) NOT NULL DEFAULT 'active', `suggested_by` INT NULL, `sort_order` INT NOT NULL DEFAULT 100, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY `uq_catalog_name` (`name`, `category`), KEY `idx_catalog_status` (`status`, `category`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `items` ADD COLUMN IF NOT EXISTS `catalog_id` INT NULL DEFAULT NULL;

ALTER TABLE `items` ADD COLUMN IF NOT EXISTS `quality` VARCHAR(12) NULL DEFAULT NULL;

ALTER TABLE `items` ADD COLUMN IF NOT EXISTS `portion` VARCHAR(40) NULL DEFAULT NULL;

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Doro Wot','ዶሮ ወጥ','Spicy chicken stew with a boiled egg, served with injera.','food','Ethiopian','ethiopian_restaurant',30,'active',10);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Siga Wot','ሥጋ ወጥ','Beef stew in berbere sauce, served with injera.','food','Ethiopian','ethiopian_restaurant',30,'active',20);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Shiro','ሽሮ','Ground chickpea stew, served with injera.','food','Ethiopian','ethiopian_restaurant,fasting',15,'active',30);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Kitfo','ክትፎ','Minced beef seasoned with mitmita and niter kibbeh.','food','Ethiopian','ethiopian_restaurant',20,'active',40);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Tibs','ጥብስ','Sauteed beef cubes with onion, pepper and rosemary.','food','Ethiopian','ethiopian_restaurant',20,'active',50);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Derek Tibs','ደረቅ ጥብስ','Dry fried beef tibs.','food','Ethiopian','ethiopian_restaurant',25,'active',60);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Zilzil Tibs','ዝልዝል ጥብስ','Strips of beef sauteed with spices.','food','Ethiopian','ethiopian_restaurant',25,'active',70);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Gored Gored','ጎረድ ጎረድ','Cubed raw beef with mitmita and awaze.','food','Ethiopian','ethiopian_restaurant',15,'active',80);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Special Firfir','ልዩ ፍርፍር','Shredded injera tossed in spicy sauce with beef.','food','Ethiopian','ethiopian_restaurant',20,'active',90);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Firfir','ፍርፍር','Shredded injera tossed in berbere sauce.','food','Ethiopian','ethiopian_restaurant,fasting',15,'active',100);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Beyaynetu','በየአይነቱ','Fasting platter of assorted vegetable and lentil dishes on injera.','food','Ethiopian','ethiopian_restaurant,fasting',20,'active',110);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Misir Wot','ምስር ወጥ','Spicy red lentil stew.','food','Ethiopian','ethiopian_restaurant,fasting',20,'active',120);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Kik Alicha','ክክ አልጫ','Mild split pea stew.','food','Ethiopian','ethiopian_restaurant,fasting',20,'active',130);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Gomen','ጎመን','Sauteed collard greens.','food','Ethiopian','ethiopian_restaurant,fasting',20,'active',140);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Atkilt','አትክልት','Cabbage, carrot and potato stew.','food','Ethiopian','ethiopian_restaurant,fasting',20,'active',150);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Dulet','ዱለት','Minced tripe, liver and beef with spices.','food','Ethiopian','ethiopian_restaurant',20,'active',160);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Quanta Firfir','ቋንጣ ፍርፍር','Dried beef with shredded injera.','food','Ethiopian','ethiopian_restaurant',25,'active',170);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Genfo','ገንፎ','Thick barley porridge with spiced butter and berbere.','food','Ethiopian','ethiopian_restaurant,breakfast',20,'active',180);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Chechebsa','ጨጨብሳ','Pieces of flatbread tossed in spiced butter.','food','Ethiopian','ethiopian_restaurant,breakfast',15,'active',190);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Ful','ፉል','Stewed fava beans with bread.','food','Ethiopian','ethiopian_restaurant,breakfast,cafe',15,'active',200);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Enkulal Firfir','እንቁላል ፍርፍር','Scrambled eggs with tomato and onion.','food','Ethiopian','ethiopian_restaurant,breakfast',15,'active',210);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Injera','እንጀራ','Extra injera.','food','Ethiopian','ethiopian_restaurant',5,'active',220);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Burger','በርገር','Beef burger with lettuce, tomato and cheese.','food','Fast food','fast_food',15,'active',230);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Cheese Burger','ቺዝ በርገር','Beef burger with double cheese.','food','Fast food','fast_food',15,'active',240);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Chicken Burger','የዶሮ በርገር','Crispy chicken burger.','food','Fast food','fast_food',15,'active',250);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Fried Chicken','የተጠበሰ ዶሮ','Crispy fried chicken pieces.','food','Fast food','fast_food',20,'active',260);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('French Fries','ጥብስ ድንች','Golden fried potatoes.','food','Fast food','fast_food,pizza_shop',10,'active',270);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Club Sandwich','ክለብ ሳንድዊች','Triple-layer sandwich with chicken, egg and vegetables.','food','Fast food','fast_food,cafe',12,'active',280);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Shawarma','ሻዋርማ','Spiced meat wrap with garlic sauce.','food','Fast food','fast_food',12,'active',290);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Hot Dog','ሆት ዶግ','Sausage in a soft bun.','food','Fast food','fast_food',10,'active',300);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Pizza Margherita','ፒዛ ማርጋሪታ','Tomato sauce, mozzarella and basil.','food','Pizza & Pasta','pizza_shop',20,'active',310);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Pizza Pepperoni','ፒዛ ፔፐሮኒ','Tomato sauce, mozzarella and pepperoni.','food','Pizza & Pasta','pizza_shop',20,'active',320);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Pizza Vegetarian','የአትክልት ፒዛ','Mixed vegetables and mozzarella.','food','Pizza & Pasta','pizza_shop',20,'active',330);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Pizza Special','ልዩ ፒዛ','House special with beef, vegetables and extra cheese.','food','Pizza & Pasta','pizza_shop',25,'active',340);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Spaghetti Bolognese','ስፓጌቲ','Spaghetti with minced beef tomato sauce.','food','Pizza & Pasta','pizza_shop,ethiopian_restaurant',20,'active',350);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Lasagna','ላዛኛ','Baked layered pasta with meat sauce and cheese.','food','Pizza & Pasta','pizza_shop',25,'active',360);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Macchiato','ማኪያቶ','Espresso with a splash of steamed milk.','food','Coffee & Tea','cafe,ethiopian_restaurant',5,'active',370);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Espresso','ኤስፕሬሶ','Single shot of espresso.','food','Coffee & Tea','cafe',5,'active',380);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Cappuccino','ካፑቺኖ','Espresso with steamed milk foam.','food','Coffee & Tea','cafe',7,'active',390);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Latte','ላቴ','Espresso with plenty of steamed milk.','food','Coffee & Tea','cafe',7,'active',400);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Buna (Ethiopian Coffee)','ቡና','Traditionally brewed Ethiopian coffee.','food','Coffee & Tea','cafe,ethiopian_restaurant',10,'active',410);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Shai (Tea)','ሻይ','Hot spiced tea.','food','Coffee & Tea','cafe,ethiopian_restaurant',5,'active',420);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Hot Chocolate','ትኩስ ቸኮሌት','Rich hot chocolate.','food','Coffee & Tea','cafe',7,'active',430);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Avocado Juice','አቮካዶ ጁስ','Fresh blended avocado juice.','food','Juice','juice_bar,cafe',8,'active',440);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Mango Juice','ማንጎ ጁስ','Fresh mango juice.','food','Juice','juice_bar,cafe',8,'active',450);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Papaya Juice','ፓፓያ ጁስ','Fresh papaya juice.','food','Juice','juice_bar,cafe',8,'active',460);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Mixed Fruit Juice (Spris)','ስፕሪስ','Layered avocado, mango and papaya juice.','food','Juice','juice_bar,cafe',8,'active',470);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Orange Juice','ብርቱካን ጁስ','Freshly squeezed orange juice.','food','Juice','juice_bar,cafe',8,'active',480);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Soft Drink','ለስላሳ መጠጥ','Cola, Fanta, Sprite or Mirinda.','food','Drinks','ethiopian_restaurant,fast_food,pizza_shop,cafe',1,'active',490);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Bottled Water','ውሃ','Bottled mineral water.','food','Drinks','ethiopian_restaurant,fast_food,pizza_shop,cafe,juice_bar',1,'active',500);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Croissant','ክሮሳን','Buttery baked croissant.','food','Bakery','bakery,cafe',5,'active',510);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Donut','ዶናት','Sweet glazed donut.','food','Bakery','bakery,cafe',5,'active',520);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Chocolate Cake Slice','የቸኮሌት ኬክ','A slice of chocolate cake.','food','Bakery','bakery,cafe',5,'active',530);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Birthday Cake','የልደት ኬክ','Custom birthday cake.','food','Bakery','bakery',60,'active',540);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Sambusa','ሳምቡሳ','Fried pastry filled with lentils or meat.','food','Bakery','bakery,cafe,ethiopian_restaurant',10,'active',550);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Bread Loaf','ዳቦ','Fresh bread.','grocery','Staples','grocery_basics,bakery',5,'active',560);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Eggs (6 pcs)','እንቁላል','Half a dozen fresh eggs.','grocery','Staples','grocery_basics',5,'active',570);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Milk 1L','ወተት','One litre of fresh milk.','grocery','Staples','grocery_basics',5,'active',580);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Sugar 1kg','ስኳር','One kilogram of sugar.','grocery','Staples','grocery_basics',5,'active',590);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Cooking Oil 1L','የምግብ ዘይት','One litre of cooking oil.','grocery','Staples','grocery_basics',5,'active',600);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Teff Flour 1kg','ጤፍ ዱቄት','One kilogram of teff flour.','grocery','Staples','grocery_basics',5,'active',610);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Rice 1kg','ሩዝ','One kilogram of rice.','grocery','Staples','grocery_basics',5,'active',620);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Pasta 500g','ፓስታ','500g pasta.','grocery','Staples','grocery_basics',5,'active',630);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Paracetamol 500mg','ፓራሲታሞል','Pain and fever relief tablets.','pharmacy','Pharmacy','pharmacy_basics',10,'active',640);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Vitamin C','ቫይታሚን ሲ','Vitamin C tablets.','pharmacy','Pharmacy','pharmacy_basics',10,'active',650);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Hand Sanitizer','ሳኒታይዘር','Alcohol hand sanitizer.','pharmacy','Pharmacy','pharmacy_basics',5,'active',660);

INSERT IGNORE INTO `menu_catalog` (`name`,`name_am`,`description`,`category`,`cuisine`,`packs`,`prep_time`,`status`,`sort_order`) VALUES ('Face Masks (pack)','የፊት ጭንብል','Pack of disposable masks.','pharmacy','Pharmacy','pharmacy_basics',5,'active',670);
