# Etel Madeya - Gas Queue Management System

<div align="center">

![Etel Madeya Logo](assets/img/etel-logo.png)

**Professional Gas Station Queue Management System**

[![Version](https://img.shields.io/badge/version-1.0.0-gold.svg)](https://github.com)
[![PHP](https://img.shields.io/badge/PHP-7.4+-blue.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7+-orange.svg)](https://mysql.com)
[![License](https://img.shields.io/badge/license-Proprietary-red.svg)](LICENSE)

</div>

---

## 🚨 IMPORTANT: Start Here!

### 📖 **READ THIS FIRST:** [README_FIRST.md](README_FIRST.md)

This file will guide you to the right documentation for your situation.

---

## 🚀 Quick Links

### 🔥 Urgent - Fix Connection Error
→ **[NEXT_STEPS.md](NEXT_STEPS.md)** - Fix "Connection error" in 5 minutes

### 📦 Fresh Installation
→ **[INSTALLATION.md](INSTALLATION.md)** - Complete installation guide

### 🔍 Something Not Working?
→ **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** - Common issues & solutions

### 📋 Quick Reference
→ **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - URLs, credentials, commands

### 📚 All Documentation
→ **[INDEX.md](INDEX.md)** - Complete documentation index

---

## 🌐 System URLs

### Live Server
- **Driver Interface:** https://ethelmarket.com/Etel-Madeya/
- **Controller Panel:** https://ethelmarket.com/Etel-Madeya/controller.php
- **Admin Panel:** https://ethelmarket.com/Etel-Madeya/admin/

### Localhost
- **Driver Interface:** http://localhost/Etel-Madeya/
- **Controller Panel:** http://localhost/Etel-Madeya/controller.php
- **Admin Panel:** http://localhost/Etel-Madeya/admin/

---

## 🔑 Default Login Credentials

### Controller Panel
- **Username:** `madeya`
- **Password:** `CHANGE_ME`

### Admin Panel
- **Username:** `admin`
- **Password:** `CHANGE_ME`

⚠️ **IMPORTANT:** Change these passwords after first login!

---

## ✨ Features

### For Drivers
- ✅ Check queue position by plate number
- ✅ View estimated wait time
- ✅ Get alerts when turn is near
- ✅ Bilingual support (English/Amharic)

### For Controllers
- ✅ Register vehicles in queue
- ✅ Bulk import from text file
- ✅ Manage queue (serve, move, remove)
- ✅ VIP and waiting queues
- ✅ Real-time statistics

### For Admins
- ✅ Manage controllers
- ✅ Manage vehicle types
- ✅ Monitor all queues
- ✅ View reports
- ✅ Activity logging
- ✅ System settings

---

## 🎨 Design

Beautiful golden theme matching the Etel Madeya logo:
- Golden/bronze color scheme
- Dark elegant background
- Animated sparkling particles
- Responsive mobile design
- Professional UI/UX

---

## 🔒 Security

- Session-based authentication
- Password hashing (bcrypt)
- SQL injection protection (PDO)
- XSS protection
- .htaccess security rules
- Activity logging
- Rate limiting

---

## 📋 System Requirements

### Minimum
- PHP 7.4+
- MySQL 5.7+
- Apache with mod_rewrite
- 50MB disk space

### Recommended
- PHP 8.0+
- MySQL 8.0+
- SSL certificate (HTTPS)
- 256MB PHP memory limit

---

## 🚀 Quick Start

### 1. Upload Files
Upload all files to your server:
- Live: `public_html/Etel-Madeya/`
- Local: `C:\xampp\htdocs\Etel-Madeya\`

### 2. Create Database
- Go to cPanel → MySQL Databases
- Create database: `Etel-Madeya`
- Create user with strong password
- Add user to database (ALL PRIVILEGES)

### 3. Import Database
- Go to phpMyAdmin
- Select your database
- Import `database.sql`
- Should create 9 tables

### 4. Configure
Edit `includes/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('SITE_URL', 'https://yourdomain.com/Etel-Madeya');
```

### 5. Test
Visit: `https://yourdomain.com/Etel-Madeya/test-db-connection.php`

Should show all ✅ (green checkmarks)

**IMPORTANT:** Delete `test-db-connection.php` after testing!

### 6. Login
Try logging in to controller and admin panels with default credentials.

**For detailed instructions, see [INSTALLATION.md](INSTALLATION.md)**

---

## 📚 Documentation

| File | Description | When to Read |
|------|-------------|--------------|
| [README_FIRST.md](README_FIRST.md) | Start here! | Right now |
| [NEXT_STEPS.md](NEXT_STEPS.md) | Fix connection error | When login fails |
| [INSTALLATION.md](INSTALLATION.md) | Complete installation | Fresh setup |
| [TROUBLESHOOTING.md](TROUBLESHOOTING.md) | Common issues | When stuck |
| [QUICK_REFERENCE.md](QUICK_REFERENCE.md) | Quick reference | Keep handy |
| [SUMMARY.md](SUMMARY.md) | Project overview | To understand system |
| [INDEX.md](INDEX.md) | Documentation index | To find guides |

---

## 🗄️ Database Structure

9 tables:
1. **admin_users** - Admin accounts
2. **controllers** - Controller accounts
3. **vehicle_types** - Vehicle categories
4. **queue** - Active queue
5. **report** - Served vehicles
6. **activity_log** - Admin activity
7. **settings** - System settings
8. **fuel_allocations** - Fuel tracking
9. **notifications** - System notifications

---

## 📁 File Structure

```
Etel-Madeya/
├── index.php                    # Driver interface
├── controller.php               # Controller panel
├── database.sql                 # Database structure
├── .htaccess                    # Apache config
│
├── includes/
│   ├── config.php              # Configuration ⚠️
│   ├── database-new.php        # Database class
│   └── ...
│
├── admin/
│   ├── index.php               # Admin panel
│   └── api.php                 # Admin API
│
├── api/
│   └── index.php               # Driver & Controller API
│
├── assets/
│   ├── css/                    # Stylesheets
│   ├── js/                     # JavaScript
│   └── img/                    # Images
│
└── Documentation/
    ├── README_FIRST.md         # Start here
    ├── NEXT_STEPS.md           # Quick fix
    ├── INSTALLATION.md         # Full guide
    └── ...
```

---

## 🔧 Troubleshooting

### Connection Error
→ Update database credentials in `includes/config.php`  
→ See [NEXT_STEPS.md](NEXT_STEPS.md)

### Login Fails
→ Check browser console (F12)  
→ Run `test-db-connection.php`  
→ See [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

### Logo Not Showing
→ Check `assets/img/etel-logo.png` exists  
→ Clear browser cache (Ctrl+F5)

### White Screen
→ Enable error display in `config.php`  
→ Check PHP error logs

**For more solutions, see [TROUBLESHOOTING.md](TROUBLESHOOTING.md)**

---

## 📞 Support

**Contact Information:**
- **Email:** ethelmarket@gmail.com
- **Phone:** +251-467-34-64-41
- **Address:** Addis Ababa, Ethiopia
- **Hours:** Mon-Sun: 24/7

**Before Contacting:**
1. Read [README_FIRST.md](README_FIRST.md)
2. Try [NEXT_STEPS.md](NEXT_STEPS.md)
3. Check [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
4. Run `test-db-connection.php`
5. Check browser console (F12)

---

## ✅ Pre-Launch Checklist

- [ ] Files uploaded to server
- [ ] Database created
- [ ] Database user created
- [ ] User added to database
- [ ] database.sql imported
- [ ] config.php updated
- [ ] test-db-connection.php shows ✅
- [ ] Admin login works
- [ ] Controller login works
- [ ] Driver interface works
- [ ] test-db-connection.php deleted
- [ ] Default passwords changed
- [ ] SSL certificate installed

---

## 🎯 Current Status

### ✅ Working
- Localhost: Fully functional
- All features implemented
- Beautiful golden theme
- Complete documentation

### ⚠️ Needs Attention
- Live server: Database connection error
- **Fix:** Update `config.php` with cPanel credentials
- **Time:** 5 minutes
- **Guide:** [NEXT_STEPS.md](NEXT_STEPS.md)

---

## 📊 Project Info

- **Name:** Etel Madeya Gas Queue Management System
- **Version:** 1.0.0
- **Status:** Ready for Production
- **Language:** PHP 7.4+
- **Database:** MySQL 5.7+
- **Framework:** Standalone (no framework)
- **Theme:** Golden/Bronze
- **License:** Proprietary
- **Developer:** Kiro AI Assistant
- **Client:** Ethel Market
- **Date:** April 16, 2026

---

## 🎉 What's Next?

1. **Read [README_FIRST.md](README_FIRST.md)** - Understand the situation
2. **Follow [NEXT_STEPS.md](NEXT_STEPS.md)** - Fix database connection
3. **Test the system** - Try all features
4. **Change passwords** - Secure the system
5. **Go live!** - Start using it

---

## 📝 License

Proprietary software. All rights reserved.

Copyright © 2026 Ethel Market

---

## 🙏 Acknowledgments

- Built with ❤️ by Kiro AI Assistant
- Designed for Ethel Market
- Serving the community of Addis Ababa, Ethiopia

---

<div align="center">

**🚀 Ready to launch! Just fix that database connection and you're live! 🚀**

**👉 Start with [README_FIRST.md](README_FIRST.md) 👈**

---

**Made with ❤️ in Ethiopia**

</div>
