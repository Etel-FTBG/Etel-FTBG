<?php
date_default_timezone_set('Africa/Addis_Ababa');
/**
 * 
 *          ETEL DELIVERY  DATABASE CONFIGURATION          
 *                                                           
 *   This file is a TEMPLATE. Real credentials live in      
 *   api/.env (not committed, not in archives).              
 *                                                           
 *   To deploy:                                              
 *     1. Copy api/.env.example  api/.env                  
 *     2. Fill in your real values in api/.env              
 *     3. Never put passwords in this file                   
 *                                                           
 *   cPanel tip: DB names/users are prefixed with your      
 *   cPanel username. Example:                               
 *     cPanel user = "ethelmar"                              
 *     DB_NAME     = "ethelmar_etel_delivery"                
 *     DB_USER     = "ethelmar_etel_delivery_user"           
 *   Find exact names in: cPanel  MySQL Databases           
 * 
 */

//  DATABASE  leave empty here; fill values in api/.env 
// These constants are only used as a last-resort fallback when
// .env is missing. NEVER put real passwords below.
define('CFG_DB_HOST',      'localhost');
define('CFG_DB_NAME',      '');   // fill in api/.env  DB_NAME
define('CFG_DB_USER',      '');   // fill in api/.env  DB_USER
define('CFG_DB_PASS',      '');   // fill in api/.env  DB_PASS

//  SECURITY TOKEN  always set in api/.env, never here 
define('CFG_TOKEN_SECRET', '');   // fill in api/.env  TOKEN_SECRET

//  GOOGLE MAPS  set in api/.env, never here 
define('CFG_MAPS_KEY',     '');   // fill in api/.env  GOOGLE_MAPS_API_KEY

// EtelGroup Service Key 2026-06-15
//  ETELID SERVICE KEY  set in api/.env, never here 
   define('CFG_ETELID_SERVICE_KEY', '');  // fill in api/.env  ETELID_SERVICE_KEY
