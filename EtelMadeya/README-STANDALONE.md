# Etel Madeya Gas Queue System - Standalone Version
## የኢትል ማደያ የነዳጅ ወረፋ ስርዓት

---

## 📋 Requirements

- **PHP 7.4+** with PDO MySQL extension
- **MySQL 5.7+** or MariaDB 10.3+
- **Apache/Nginx** web server
- **mod_rewrite** enabled (for Apache)

---

## 🚀 Installation Steps

### 1. Database Setup

```bash
# Create database and import schema
mysql -u root -p < database.sql
```

Or manually:
1. Open phpMyAdmin or MySQL client
2. Create database: `Etel-Madeya`
3. Import `database.sql` file

### 2. Configuration

Edit `includes/config.php` and update:

```php
// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'Etel-Madeya');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');

// Site URL
define('SITE_URL', 'http://yourdomain.com');
```

### 3. File Permissions

```bash
# Make uploads directory writable
chmod 755 uploads/
chmod 755 assets/img/
```

### 4. Default Login Credentials

**Controller Login:**
- Username: `madeya`
- Password: `CHANGE_ME`

**Admin Panel:**
- URL: `http://yourdomain.com/admin/`
- Username: `admin`
- Password: `CHANGE_ME`

⚠️ **IMPORTANT:** Change all default passwords immediately after first login!

---

## 📁 Project Structure

```
madeya-gas-queue/
├── admin/                  # Admin panel
│   ├── index.php          # Admin interface
│   └── api.php            # Admin API endpoints
├── api/                   # Queue API
│   └── index.php          # Driver & Controller API
├── assets/                # Static assets
│   ├── css/
│   │   ├── driver-style.css
│   │   ├── controller-style.css
│   │   └── admin-style.css
│   ├── js/
│   │   ├── driver-app.js
│   │   ├── controller-app.js
│   │   └── admin-app.js
│   └── img/
│       └── etel-logo.png
├── includes/              # Core files
│   ├── config.php         # Configuration
│   ├── database-new.php   # Database class (NEW)
│   ├── database.php       # Old WordPress DB (DEPRECATED)
│   └── security.php       # Security functions
├── uploads/               # Uploaded files
├── index.php              # Main driver interface (NEW)
├── controller.php         # Controller panel (TO CREATE)
├── database.sql           # Database schema (NEW)
├── README-STANDALONE.md   # This file
└── README.md              # Old WordPress plugin readme
```

---

## 🎨 Features

### Driver Features
- ✅ Check queue position by plate number
- ✅ Real-time position updates
- ✅ Alert when within 30 positions
- ✅ Multi-language support (English & Amharic)
- ✅ Register in queue
- ✅ View estimated wait time

### Controller Features
- ✅ Manage queue (Normal, VIP, Waiting)
- ✅ Serve vehicles
- ✅ Bulk CSV import
- ✅ Odd/Even plate filtering (ሙሉ / ጎዶሎ)
- ✅ Fuel stock tracking
- ✅ Vehicle type management
- ✅ Priority tier system (7 government levels)
- ✅ Real-time queue statistics

### Admin Features
- ✅ Dashboard with analytics
- ✅ Controller management (CRUD)
- ✅ Vehicle type configuration
- ✅ Fuel stock management
- ✅ Queue reports and history
- ✅ Settings configuration
- ✅ Activity logging
- ✅ Station location management

---

## 🔧 Configuration Options

### Queue Settings
```php
define('ALERT_THRESHOLD', 30);      // Alert when within 30 positions
define('MAX_QUEUE_SIZE', 500);      // Maximum queue size
define('SESSION_TIMEOUT', 3600 * 8); // 8 hours
```

### Fuel Types
```php
define('FUEL_TYPES', [
    'benzene' => 'Benzene (ቤንዚን)',
    'diesel' => 'Diesel (ናፍጣ)',
    'kerosene' => 'Kerosene (ኬሮሲን)'
]);
```

### Vehicle Types
Default vehicle types are configured in the database and can be managed through the admin panel.

---

## 🔄 Migration from WordPress Plugin

This is a **standalone version** that does NOT require WordPress. Key differences:

### What Changed:
1. **No WordPress dependency** - Pure PHP application
2. **New database structure** - Uses `Etel-Madeya` database
3. **Standalone routing** - Uses `index.php?route=api` instead of WordPress AJAX
4. **New file structure** - Follows SamketGift pattern
5. **PDO database class** - Replaces `$wpdb` with PDO
6. **Session-based auth** - No WordPress user system

### Migration Steps:
1. **Export your data** from WordPress plugin tables
2. **Import database.sql** to create new structure
3. **Migrate data** using provided migration script (if needed)
4. **Update config.php** with your settings
5. **Test all functionality**

---

## 🛡️ Security Best Practices

1. **Change default passwords immediately**
2. **Use strong database passwords**
3. **Keep PHP and MySQL updated**
4. **Enable HTTPS in production**
5. **Backup database regularly**
6. **Set proper file permissions**
7. **Monitor activity logs**
8. **Disable display_errors in production**

---

## 🐛 Troubleshooting

### Database Connection Error
- Check database credentials in `includes/config.php`
- Verify MySQL service is running
- Ensure database `Etel-Madeya` exists

### Controller Login Not Working
- Clear browser cache and cookies
- Check if sessions are enabled in PHP
- Verify controller user exists in `controllers` table

### Queue Not Showing
- Check if controller is marked as `is_active = 1`
- Verify database connection
- Check browser console for JavaScript errors

### API Errors
- Ensure `index.php?route=api` routing works
- Check `.htaccess` for mod_rewrite rules
- Verify API endpoints in `api/index.php`

---

## 📊 Database Tables

- `controllers` - Controller accounts and station info
- `vehicle_types` - Vehicle type definitions
- `queue` - Active queue entries
- `report` - Served vehicles history
- `fuel_stock` - Fuel inventory per controller
- `fuel_log` - Fuel transaction history
- `admin_users` - Admin panel accounts
- `activity_log` - Admin activity tracking
- `settings` - System settings

---

## 🔄 API Endpoints

### Driver API
- `GET /index.php?route=api&action=check_position&plate=XXX` - Check position
- `POST /index.php?route=api&action=register` - Register in queue

### Controller API
- `POST /index.php?route=api&action=controller_login` - Login
- `GET /index.php?route=api&action=get_queue` - Get queue
- `POST /index.php?route=api&action=serve_vehicle` - Serve vehicle
- `POST /index.php?route=api&action=bulk_import` - Bulk CSV import

### Admin API
- `POST /admin/api.php?action=login` - Admin login
- `GET /admin/api.php?action=get_stats` - Dashboard stats
- `POST /admin/api.php?action=create_controller` - Create controller
- `POST /admin/api.php?action=update_fuel` - Update fuel stock

---

## 📞 Support

For issues or questions:
- Email: info@etelmadeya.com
- Phone: +251-911-000-000

---

## 📝 License

© 2026 Etel Madeya. All rights reserved.

---

## 🙏 Credits

Developed for Ethiopian gas stations to manage fuel queues efficiently.

**May this system serve Ethiopia well.**
**ይህ ስርዓት ኢትዮጵያን በደንብ ያገለግል**

---

## 🎯 Quick Start Checklist

- [ ] Import database.sql
- [ ] Update config.php with your settings
- [ ] Set file permissions
- [ ] Upload logo to assets/img/etel-logo.png
- [ ] Login to controller panel
- [ ] Login to admin panel
- [ ] Change all default passwords
- [ ] Configure vehicle types
- [ ] Set fuel stock
- [ ] Test queue registration
- [ ] Test serving vehicles
- [ ] Launch! 🚀

---

## 📖 Next Steps

1. **Create controller.php** - Controller panel interface
2. **Create admin panel** - Full admin interface
3. **Create API endpoints** - All API functionality
4. **Create CSS files** - Styling for all interfaces
5. **Create JS files** - Frontend functionality
6. **Test everything** - Comprehensive testing
7. **Deploy** - Production deployment

