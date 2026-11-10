-- E-Ticket database (MySQL / MariaDB) — schema + demo data
-- Import in phpMyAdmin (Import tab) or: mysql -u root < eticket.sql
-- Demo accounts: admin@eticket.test / admin123 · attendee@eticket.test / attendee123
-- Same tables as the Laravel migrations in database/migrations (includes the "migrations" table,
-- so `php artisan migrate` sees the schema as up to date).

CREATE DATABASE IF NOT EXISTS `eticket` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `eticket`;

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: eticket
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `attendee_details`
--

DROP TABLE IF EXISTS `attendee_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendee_details` (
  `attendee_ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_ID` int(10) unsigned NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `ticket_code` varchar(30) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `checked_in_at` datetime DEFAULT NULL,
  `checked_in_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`attendee_ID`),
  UNIQUE KEY `uq_ticket_code` (`ticket_code`),
  KEY `idx_att_res` (`reservation_ID`),
  KEY `fk_att_admin` (`checked_in_by`),
  CONSTRAINT `fk_att_admin` FOREIGN KEY (`checked_in_by`) REFERENCES `users` (`user_ID`) ON DELETE SET NULL,
  CONSTRAINT `fk_att_res` FOREIGN KEY (`reservation_ID`) REFERENCES `reservations` (`reservation_ID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendee_details`
--

LOCK TABLES `attendee_details` WRITE;
/*!40000 ALTER TABLE `attendee_details` DISABLE KEYS */;
INSERT INTO `attendee_details` VALUES (1,1,'Maria Santos','0917 000 8237','TKT-001-1-T79ZFZ8R',1,'2026-11-10 11:34:28',1),(2,1,'Carlo Santos','0917 000 2536','TKT-001-2-UVZPGSKA',0,NULL,NULL),(3,2,'Juan Dela Cruz','0917 000 4042','TKT-002-1-LP3ZDBCU',0,NULL,NULL),(4,2,'Liza Dela Cruz','0917 000 2597','TKT-002-2-RWZ7UYFV',0,NULL,NULL),(5,2,'Marco Dela Cruz','0917 000 4058','TKT-002-3-7ZBULDSW',0,NULL,NULL),(6,3,'Ana Reyes','0917 000 6674',NULL,0,NULL,NULL),(7,4,'Maria Santos','0917 000 1584',NULL,0,NULL,NULL),(8,5,'Juan Dela Cruz','0917 000 7005',NULL,0,NULL,NULL);
/*!40000 ALTER TABLE `attendee_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `events` (
  `event_ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_ID` int(10) unsigned NOT NULL,
  `event_name` varchar(150) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'General',
  `description` text DEFAULT NULL,
  `event_date` date NOT NULL,
  `event_time` time NOT NULL,
  `venue` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`event_ID`),
  KEY `idx_events_date` (`event_date`,`event_time`),
  KEY `idx_events_category` (`category`),
  KEY `fk_events_user` (`user_ID`),
  CONSTRAINT `fk_events_user` FOREIGN KEY (`user_ID`) REFERENCES `users` (`user_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `events`
--

LOCK TABLES `events` WRITE;
/*!40000 ALTER TABLE `events` DISABLE KEYS */;
INSERT INTO `events` VALUES (1,1,'Fun Run 2026','Sports','5K community fun run open to students and the public. Includes race bib, finisher shirt and hydration stations.','2026-12-01','05:30:00','TSU Main Campus Oval, Tarlac City','2026-11-10 11:34:28'),(2,1,'CCS Tech Summit','Seminar','A one-day conference on web, mobile and cloud development with industry speakers and a career fair.','2026-11-24','09:00:00','TSU Convention Hall, Tarlac City','2026-11-10 11:34:28'),(3,1,'Campus Acoustic Night','Concert','An evening of live acoustic performances by student bands and guest artists.','2026-12-10','18:00:00','TSU Amphitheater, Tarlac City','2026-11-10 11:34:28'),(4,1,'Web Dev Workshop: Laravel Basics','Workshop','Hands-on workshop covering routing, controllers and Eloquent. Bring your own laptop.','2026-11-17','13:00:00','CCS Computer Lab 2, TSU','2026-11-10 11:34:28');
/*!40000 ALTER TABLE `events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'01_create_users_table',1),(2,'02_create_events_table',1),(3,'03_create_ticket_types_table',1),(4,'04_create_reservations_table',1),(5,'05_create_attendee_details_table',1),(6,'06_create_payments_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `payment_ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_ID` int(10) unsigned NOT NULL,
  `amount` decimal(8,2) NOT NULL,
  `payment_method` varchar(100) DEFAULT NULL,
  `payment_status` enum('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  `payment_date` datetime DEFAULT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`payment_ID`),
  UNIQUE KEY `uq_payment_reservation` (`reservation_ID`),
  KEY `fk_pay_admin` (`recorded_by`),
  CONSTRAINT `fk_pay_admin` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_ID`) ON DELETE SET NULL,
  CONSTRAINT `fk_pay_res` FOREIGN KEY (`reservation_ID`) REFERENCES `reservations` (`reservation_ID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,1,500.00,'Cash','paid','2026-11-10 11:34:28',1),(2,2,0.00,'Cash','paid','2026-11-10 11:34:28',1),(3,3,250.00,'Pay at venue (cash)','unpaid',NULL,NULL),(4,4,0.00,'Free','paid','2026-11-10 11:34:28',NULL),(5,5,250.00,'Pay at venue (cash)','unpaid',NULL,NULL);
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reservations`
--

DROP TABLE IF EXISTS `reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservations` (
  `reservation_ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_ID` int(10) unsigned NOT NULL,
  `ticket_ID` int(10) unsigned NOT NULL,
  `quantity` tinyint(3) unsigned NOT NULL,
  `total_amount` decimal(8,2) NOT NULL,
  `reservation_status` enum('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  `reservation_date` datetime NOT NULL,
  PRIMARY KEY (`reservation_ID`),
  KEY `idx_res_user` (`user_ID`),
  KEY `idx_res_ticket` (`ticket_ID`),
  KEY `idx_res_status` (`reservation_status`),
  CONSTRAINT `fk_res_ticket` FOREIGN KEY (`ticket_ID`) REFERENCES `ticket_types` (`ticket_ID`),
  CONSTRAINT `fk_res_user` FOREIGN KEY (`user_ID`) REFERENCES `users` (`user_ID`),
  CONSTRAINT `chk_res_qty` CHECK (`quantity` between 1 and 10)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reservations`
--

LOCK TABLES `reservations` WRITE;
/*!40000 ALTER TABLE `reservations` DISABLE KEYS */;
INSERT INTO `reservations` VALUES (1,2,2,2,500.00,'confirmed','2026-11-10 11:34:28'),(2,3,1,3,0.00,'confirmed','2026-11-10 11:34:28'),(3,4,2,1,250.00,'pending','2026-11-10 11:34:28'),(4,2,1,1,0.00,'pending','2026-11-10 11:34:28'),(5,3,2,1,250.00,'cancelled','2026-11-10 11:34:28');
/*!40000 ALTER TABLE `reservations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_types`
--

DROP TABLE IF EXISTS `ticket_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ticket_types` (
  `ticket_ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_ID` int(10) unsigned NOT NULL,
  `type_name` varchar(50) NOT NULL,
  `price` decimal(8,2) NOT NULL DEFAULT 0.00,
  `quantity_available` int(10) unsigned NOT NULL,
  `quantity_sold` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`ticket_ID`),
  UNIQUE KEY `uq_ticket_type_per_event` (`event_ID`,`type_name`),
  CONSTRAINT `fk_tickets_event` FOREIGN KEY (`event_ID`) REFERENCES `events` (`event_ID`) ON DELETE CASCADE,
  CONSTRAINT `chk_ticket_price` CHECK (`price` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_types`
--

LOCK TABLES `ticket_types` WRITE;
/*!40000 ALTER TABLE `ticket_types` DISABLE KEYS */;
INSERT INTO `ticket_types` VALUES (1,1,'Regular',0.00,46,4),(2,2,'Regular',250.00,297,3),(3,2,'VIP',750.00,40,0),(4,3,'General Admission',150.00,200,0),(5,3,'VIP',400.00,30,0),(6,4,'Regular',100.00,30,0);
/*!40000 ALTER TABLE `ticket_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_Name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','attendee') NOT NULL DEFAULT 'attendee',
  `created_at` datetime NOT NULL,
  `session_version` int(10) unsigned NOT NULL DEFAULT 1,
  `reset_code_hash` char(64) DEFAULT NULL,
  `reset_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_ID`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Event Administrator','admin@eticket.test','$2y$12$6URxWxAYO.C5XCRPPPBIueHPnfDQbcKUKJg4Pnf6EV3FJgtG96oiS','admin','2026-11-10 11:34:27',1,NULL,NULL),(2,'Maria Santos','attendee@eticket.test','$2y$12$rnVw9gZ.zaPBaSB/cs1uv.6wLqdwKRj14Frc95.gdkw1J5CQK3X4q','attendee','2026-11-10 11:34:28',1,NULL,NULL),(3,'Juan Dela Cruz','juan@eticket.test','$2y$12$yri3qxfloOHynYFubft7x.IJ773.6ShuZXKEfjLu7KU0o9pv5S/BK','attendee','2026-11-10 11:34:28',1,NULL,NULL),(4,'Ana Reyes','ana@eticket.test','$2y$12$sQsr99ED14mqLHqjR9uZbOY023IcADR87sr1mmW2H8uhVnWfYj7hK','attendee','2026-11-10 11:34:28',1,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
