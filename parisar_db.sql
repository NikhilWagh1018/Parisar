-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 22, 2026 at 07:05 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `parisar_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_sessions`
--

DROP TABLE IF EXISTS `audit_sessions`;
CREATE TABLE IF NOT EXISTS `audit_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `user_id` int(11) NOT NULL,
  `road_id` int(11) NOT NULL,
  `status` enum('active','completed','archived') NOT NULL DEFAULT 'active',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_audit_sessions_public_id` (`public_id`),
  KEY `idx_audit_sessions_user` (`user_id`),
  KEY `idx_audit_sessions_road` (`road_id`),
  KEY `idx_audit_sessions_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_sessions`
--

INSERT INTO `audit_sessions` (`id`, `public_id`, `user_id`, `road_id`, `status`, `started_at`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 'AUD-0001', 4, 1, 'active', '2026-05-19 12:55:47', NULL, '2026-05-19 12:55:47', '2026-05-19 12:55:47'),
(2, 'AUD-0002', 4, 2, 'active', '2026-05-19 13:18:56', NULL, '2026-05-19 13:18:56', '2026-05-19 13:18:56'),
(3, 'AUD-0003', 4, 3, 'active', '2026-05-19 15:39:17', NULL, '2026-05-19 15:39:17', '2026-05-19 15:39:17'),
(4, 'AUD-0004', 4, 4, 'active', '2026-05-19 16:02:21', NULL, '2026-05-19 16:02:21', '2026-05-19 16:02:21'),
(8, 'AUD-0008', 9, 8, 'active', '2026-05-20 00:17:06', NULL, '2026-05-20 00:17:06', '2026-05-20 00:17:06'),
(10, 'AUD-0010', 9, 10, 'active', '2026-05-20 10:27:44', NULL, '2026-05-20 10:27:44', '2026-05-20 10:27:44'),
(11, 'AUD-0011', 9, 11, 'active', '2026-05-20 12:42:08', NULL, '2026-05-20 12:42:08', '2026-05-20 12:42:08');

-- --------------------------------------------------------

--
-- Table structure for table `intersections`
--

DROP TABLE IF EXISTS `intersections`;
CREATE TABLE IF NOT EXISTS `intersections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `audit_id` int(11) NOT NULL,
  `intersection_num` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `gps_coords` varchar(100) DEFAULT NULL,
  `landmark_name` varchar(255) DEFAULT NULL,
  `off_ramp` enum('Comfortable','Uncomfortable','No Ramp') DEFAULT NULL,
  `on_ramp` enum('Comfortable','Uncomfortable','No Ramp') DEFAULT NULL,
  `markings` enum('Present','Absent') DEFAULT NULL,
  `signage` enum('Present','Absent') DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_intersections_public_id` (`public_id`),
  KEY `idx_int_audit` (`audit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `intersections`
--

INSERT INTO `intersections` (`id`, `public_id`, `audit_id`, `intersection_num`, `gps_coords`, `landmark_name`, `off_ramp`, `on_ramp`, `markings`, `signage`, `created_at`, `updated_at`) VALUES
(2, 'INT-0002', 17, 1, NULL, NULL, 'Uncomfortable', 'No Ramp', 'Present', 'Absent', '2026-05-20 12:48:01', '2026-05-20 12:48:01'),
(3, 'INT-0003', 18, 1, NULL, NULL, 'Comfortable', NULL, 'Absent', 'Absent', '2026-05-20 12:50:32', '2026-05-20 12:50:32');

-- --------------------------------------------------------

--
-- Table structure for table `obstructions`
--

DROP TABLE IF EXISTS `obstructions`;
CREATE TABLE IF NOT EXISTS `obstructions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `audit_id` int(11) NOT NULL,
  `obstruction_category` enum('fixed','movable','parked') NOT NULL,
  `obstruction_type` varchar(100) NOT NULL,
  `cyclist_slowed` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `partial_obstructions` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `total_obstructions` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_obstructions_public_id` (`public_id`),
  KEY `idx_obs_audit` (`audit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `obstructions`
--

INSERT INTO `obstructions` (`id`, `public_id`, `audit_id`, `obstruction_category`, `obstruction_type`, `cyclist_slowed`, `partial_obstructions`, `total_obstructions`, `created_at`, `updated_at`) VALUES
(7, 'OBS-0007', 17, 'fixed', 'Poles', 3, 3, 7, '2026-05-20 12:48:01', '2026-05-20 12:48:01'),
(8, 'OBS-0008', 17, 'fixed', 'CCTV', 4, 4, 4, '2026-05-20 12:48:01', '2026-05-20 12:48:01'),
(9, 'OBS-0009', 17, 'fixed', 'SignBoard', 3, 2, 1, '2026-05-20 12:48:01', '2026-05-20 12:48:01'),
(10, 'OBS-0010', 17, 'movable', 'GarbageBins', 3, 4, 0, '2026-05-20 12:48:01', '2026-05-20 12:48:01'),
(11, 'OBS-0011', 17, 'parked', 'AutoGarage', 3, 2, 0, '2026-05-20 12:48:01', '2026-05-20 12:48:01'),
(12, 'OBS-0012', 17, 'parked', 'CommercialRetailShops', 1, 4, 0, '2026-05-20 12:48:01', '2026-05-20 12:48:01'),
(13, 'OBS-0013', 18, 'fixed', 'SignBoard', 1, 2, 1, '2026-05-20 12:50:32', '2026-05-20 12:50:32'),
(14, 'OBS-0014', 18, 'fixed', 'TelephonePanel', 2, 1, 1, '2026-05-20 12:50:32', '2026-05-20 12:50:32'),
(15, 'OBS-0015', 18, 'fixed', 'ElectricalPanel', 3, 3, 5, '2026-05-20 12:50:32', '2026-05-20 12:50:32'),
(16, 'OBS-0016', 18, 'parked', 'ReligiousLandmark', 3, 1, 0, '2026-05-20 12:50:32', '2026-05-20 12:50:32'),
(17, 'OBS-0017', 18, 'parked', 'AutoGarage', 1, 3, 1, '2026-05-20 12:50:32', '2026-05-20 12:50:32');

-- --------------------------------------------------------

--
-- Table structure for table `roads`
--

DROP TABLE IF EXISTS `roads`;
CREATE TABLE IF NOT EXISTS `roads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `creator_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_point` varchar(255) DEFAULT NULL,
  `end_point` varchar(255) DEFAULT NULL,
  `total_length` decimal(8,2) DEFAULT NULL,
  `gps_start` varchar(100) DEFAULT NULL,
  `gps_end` varchar(100) DEFAULT NULL,
  `segment_method` enum('auto','manual') NOT NULL DEFAULT 'auto',
  `segment_length` decimal(8,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roads_public_id` (`public_id`),
  KEY `fk_roads_creator` (`creator_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roads`
--

INSERT INTO `roads` (`id`, `public_id`, `creator_id`, `name`, `start_point`, `end_point`, `total_length`, `gps_start`, `gps_end`, `segment_method`, `segment_length`, `created_at`, `updated_at`) VALUES
(1, 'ROAD-0001', 4, 'BANER ROAD', 'wt', 'z2', 1000.00, '63', NULL, 'auto', 500.00, '2026-05-19 12:55:47', '2026-05-19 12:55:47'),
(2, 'ROAD-0002', 4, 'HANDEWADI', 'KSE', 'handewadi chowk', 1500.00, '322453234', '5646', 'auto', 500.00, '2026-05-19 13:18:56', '2026-05-19 13:18:56'),
(3, 'ROAD-0003', 4, 'DP ROAD', 'DRFHGGJ', 'FHJFHGHD', 500.00, 'SSFG', 'FNB', 'auto', 500.00, '2026-05-19 15:39:17', '2026-05-19 15:39:17'),
(4, 'ROAD-0004', 4, 'HANDEWADI', 'sgh', 'nxx', 1200.00, NULL, NULL, 'auto', 600.00, '2026-05-19 16:02:21', '2026-05-19 16:02:21'),
(8, 'ROAD-0008', 9, 'BIBVEWADI ROAD', 'z1', 'sg', 230.00, NULL, NULL, 'auto', 300.00, '2026-05-20 00:17:06', '2026-05-20 00:17:06'),
(10, 'ROAD-0010', 9, 'HANDEWADI', 'KSOE', 'Handiwadi Chowk', 1200.00, NULL, NULL, 'auto', 500.00, '2026-05-20 10:27:44', '2026-05-20 11:08:21'),
(11, 'ROAD-0011', 9, 'HANDEWADI ROAD', 'KSOE', 'Handewadi Chowk', 1000.00, '456', '345', 'auto', 500.00, '2026-05-20 12:42:08', '2026-05-20 12:42:08');

-- --------------------------------------------------------

--
-- Table structure for table `segments`
--

DROP TABLE IF EXISTS `segments`;
CREATE TABLE IF NOT EXISTS `segments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `road_id` int(11) NOT NULL,
  `segment_number` smallint(5) UNSIGNED NOT NULL,
  `start_label` varchar(255) DEFAULT NULL,
  `end_label` varchar(255) DEFAULT NULL,
  `start_distance` decimal(8,2) NOT NULL DEFAULT 0.00,
  `end_distance` decimal(8,2) NOT NULL DEFAULT 0.00,
  `length` decimal(8,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_segments_public_id` (`public_id`),
  UNIQUE KEY `uq_segments_road_num` (`road_id`,`segment_number`),
  KEY `idx_segments_road` (`road_id`),
  KEY `idx_segments_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `segments`
--

INSERT INTO `segments` (`id`, `public_id`, `road_id`, `segment_number`, `start_label`, `end_label`, `start_distance`, `end_distance`, `length`, `status`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 'SEG-0001', 1, 1, '0m', '500m', 0.00, 500.00, 500.00, 'completed', '2026-05-19 12:56:37', '2026-05-19 12:55:47', '2026-05-19 12:56:37'),
(2, 'SEG-0002', 1, 2, '500m', '1000m', 500.00, 1000.00, 500.00, 'completed', '2026-05-19 13:09:25', '2026-05-19 12:55:47', '2026-05-19 13:09:25'),
(3, 'SEG-0003', 2, 1, '0m', '500m', 0.00, 500.00, 500.00, 'completed', '2026-05-19 13:19:05', '2026-05-19 13:18:56', '2026-05-19 13:19:05'),
(4, 'SEG-0004', 2, 2, '500m', '1000m', 500.00, 1000.00, 500.00, 'completed', '2026-05-19 13:19:18', '2026-05-19 13:18:56', '2026-05-19 13:19:18'),
(5, 'SEG-0005', 2, 3, '1000m', '1500m', 1000.00, 1500.00, 500.00, 'completed', '2026-05-19 13:19:28', '2026-05-19 13:18:56', '2026-05-19 13:19:28'),
(6, 'SEG-0006', 3, 1, '0m', '500m', 0.00, 500.00, 500.00, 'completed', '2026-05-19 15:41:05', '2026-05-19 15:39:17', '2026-05-19 15:41:05'),
(7, 'SEG-0007', 4, 1, '0m', '600m', 0.00, 600.00, 600.00, 'completed', '2026-05-19 16:05:40', '2026-05-19 16:02:21', '2026-05-19 16:05:40'),
(8, 'SEG-0008', 4, 2, '600m', '1200m', 600.00, 1200.00, 600.00, 'pending', NULL, '2026-05-19 16:02:21', '2026-05-19 16:02:21'),
(17, 'SEG-0017', 8, 1, '0m', '230m', 0.00, 230.00, 230.00, 'completed', '2026-05-20 00:17:22', '2026-05-20 00:17:06', '2026-05-20 00:17:22'),
(26, 'SEG-0026', 10, 1, '0m', '500m', 0.00, 500.00, 500.00, 'completed', '2026-05-20 11:09:34', '2026-05-20 11:08:21', '2026-05-20 11:09:34'),
(27, 'SEG-0027', 10, 2, '500m', '1000m', 500.00, 1000.00, 500.00, 'completed', '2026-05-20 11:09:45', '2026-05-20 11:08:21', '2026-05-20 11:09:45'),
(28, 'SEG-0028', 10, 3, '1000m', '1200m', 1000.00, 1200.00, 200.00, 'completed', '2026-05-20 11:09:55', '2026-05-20 11:08:21', '2026-05-20 11:09:55'),
(29, 'SEG-0029', 11, 1, '0m', '500m', 0.00, 500.00, 500.00, 'completed', '2026-05-20 12:48:01', '2026-05-20 12:42:08', '2026-05-20 12:48:01'),
(30, 'SEG-0030', 11, 2, '500m', '1000m', 500.00, 1000.00, 500.00, 'completed', '2026-05-20 12:50:32', '2026-05-20 12:42:08', '2026-05-20 12:50:32');

-- --------------------------------------------------------

--
-- Table structure for table `segment_audits`
--

DROP TABLE IF EXISTS `segment_audits`;
CREATE TABLE IF NOT EXISTS `segment_audits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `session_id` int(11) NOT NULL,
  `segment_id` int(11) NOT NULL,
  `surveyor_id` int(11) NOT NULL,
  `start_landmark` varchar(255) NOT NULL,
  `end_landmark` varchar(255) NOT NULL,
  `gps_start` varchar(100) NOT NULL,
  `gps_end` varchar(100) NOT NULL,
  `cycle_track_missing` enum('Yes','No') DEFAULT NULL,
  `missing_length` decimal(8,2) DEFAULT NULL,
  `cyclist_use` enum('Yes','No') DEFAULT NULL,
  `better_surface` enum('Cycle Track','Road') DEFAULT NULL,
  `surface_material` enum('Interlock Blocks','Concrete','Asphalt') DEFAULT NULL,
  `people_walking` enum('Yes','No') DEFAULT NULL,
  `signage_count` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `shade` enum('Yes','No','Partial') DEFAULT NULL,
  `light_after_sunset` enum('Yes','No','Partial') DEFAULT NULL,
  `track_geometry` enum('Road Level','Footpath Level','Segregated from FP&R','NA') DEFAULT NULL,
  `buffer_zone` enum('Segregated','Buffer Zone','None','NA') DEFAULT NULL,
  `surface_issues` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`surface_issues`)),
  `overhead_issues` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`overhead_issues`)),
  `footpath_rating` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`footpath_rating`)),
  `footpath_score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `segment_width` decimal(6,2) DEFAULT NULL,
  `segment_length` decimal(8,2) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_segment_audits_public_id` (`public_id`),
  KEY `idx_sa_session` (`session_id`),
  KEY `idx_sa_segment` (`segment_id`),
  KEY `idx_sa_surveyor` (`surveyor_id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `segment_audits`
--

INSERT INTO `segment_audits` (`id`, `public_id`, `session_id`, `segment_id`, `surveyor_id`, `start_landmark`, `end_landmark`, `gps_start`, `gps_end`, `cycle_track_missing`, `missing_length`, `cyclist_use`, `better_surface`, `surface_material`, `people_walking`, `signage_count`, `shade`, `light_after_sunset`, `track_geometry`, `buffer_zone`, `surface_issues`, `overhead_issues`, `footpath_rating`, `footpath_score`, `segment_width`, `segment_length`, `comments`, `created_at`, `updated_at`) VALUES
(1, 'SA-0001', 1, 1, 4, 'ddasf', 'zfadgfc', 'xcvs', 'sgsgsdv', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '[]', '[]', '[]', 0, NULL, NULL, NULL, '2026-05-19 12:56:37', '2026-05-19 12:56:37'),
(2, 'SA-0002', 1, 2, 4, 'dh', 'nzb', '423', '76543', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '[]', '[]', '[]', 0, NULL, NULL, NULL, '2026-05-19 13:09:25', '2026-05-19 13:09:25'),
(3, 'SA-0003', 2, 3, 4, 'etrhr', 'ytreh', 'trg', 'rtertw', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '[]', '[]', '[]', 0, NULL, NULL, NULL, '2026-05-19 13:19:05', '2026-05-19 13:19:05'),
(4, 'SA-0004', 2, 4, 4, 'rtjhs', 'erut', 'utytrtyt', 'fghh', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '[]', '[]', '[]', 0, NULL, NULL, NULL, '2026-05-19 13:19:18', '2026-05-19 13:19:18'),
(5, 'SA-0005', 2, 5, 4, 'dfgvsx', 'dgfhty', 'nfhg', 'rthd', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '[]', '[]', '[]', 0, NULL, NULL, NULL, '2026-05-19 13:19:28', '2026-05-19 13:19:28'),
(6, 'SA-0006', 3, 6, 4, 'ETJ', 'RTUG', 'FFJD', 'YRTH', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '[]', '[]', '[]', 0, NULL, NULL, NULL, '2026-05-19 15:41:05', '2026-05-19 15:41:05'),
(7, 'SA-0007', 4, 7, 4, 'fsj', 'sdghadf', 'dhfsd', 'sdgsd', NULL, NULL, NULL, NULL, NULL, NULL, 0, 'Yes', 'Yes', NULL, NULL, '[]', '[]', '[\"minWidth\",\"continuous\",\"disabledFriendly\"]', 60, NULL, NULL, NULL, '2026-05-19 16:05:40', '2026-05-19 16:05:40'),
(10, 'SA-0010', 8, 17, 9, 'ssfgh', 'dafhfs', '3', '345345', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '[]', '[]', '[]', 0, NULL, NULL, NULL, '2026-05-20 00:17:22', '2026-05-20 00:17:22'),
(14, 'SA-0014', 10, 26, 9, 'shinsdi', 'sdfuhi', 'sdSSESFSSDG', 'SFGSD', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '[]', '[]', '[]', 0, NULL, NULL, NULL, '2026-05-20 11:09:34', '2026-05-20 11:09:34'),
(15, 'SA-0015', 10, 27, 9, 'SDSF', 'FDGD', '23', '3453', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '[]', '[]', '[]', 0, NULL, NULL, NULL, '2026-05-20 11:09:45', '2026-05-20 11:09:45'),
(16, 'SA-0016', 10, 28, 9, '4RWET', 'DFGS', '34234', '234532', NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, '[]', '[]', '[]', 0, NULL, NULL, NULL, '2026-05-20 11:09:55', '2026-05-20 11:09:55'),
(17, 'SA-0017', 11, 29, 9, 'KSOE', 'Handewadi Chowk', '31434.454', '23.2452', 'No', NULL, 'Yes', 'Road', 'Interlock Blocks', 'Yes', 3, 'No', 'Yes', 'Road Level', 'None', '[\"loose\",\"roots\",\"manholes\"]', '[\"overheadCables\"]', '[\"continuous\",\"comfort\"]', 40, NULL, NULL, NULL, '2026-05-20 12:48:01', '2026-05-20 12:48:01'),
(18, 'SA-0018', 11, 30, 9, 'KSOE', 'Handewadi Chowk', '253', '43523', 'No', NULL, 'No', 'Road', 'Interlock Blocks', 'No', 9, 'No', 'Partial', NULL, 'Buffer Zone', '[]', '[\"branches\"]', '[\"minWidth\",\"continuous\",\"disabledFriendly\",\"comfort\"]', 80, NULL, NULL, NULL, '2026-05-20 12:50:32', '2026-05-20 12:50:32');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `name` varchar(150) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `organisation` varchar(200) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `age` tinyint(3) UNSIGNED DEFAULT NULL,
  `address` text DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('surveyor','admin') NOT NULL DEFAULT 'surveyor',
  `auth_provider` enum('local','google') NOT NULL DEFAULT 'local',
  `google_id` varchar(100) DEFAULT NULL,
  `profile_picture` varchar(512) DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_public_id` (`public_id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_google_id` (`google_id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `public_id`, `name`, `email`, `phone`, `organisation`, `gender`, `age`, `address`, `password`, `role`, `auth_provider`, `google_id`, `profile_picture`, `email_verified`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'SURV-0001', 'Nikhil Wagh', 'nikhil@cycleaudit.in', '9876543210', 'Parisar', 'Male', 22, NULL, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'surveyor', 'local', NULL, 'https://lh3.googleusercontent.com/a/ACg8ocI0ew3bNfejtPdtrtOqsEX8pusKz7e62R5I8rr6s6C-4ZLsexYEew=s96-c', 1, '2026-05-19 07:07:07', '2026-05-19 07:07:07', '2026-05-19 19:47:41'),
(2, 'SURV-0002', 'Test Admin', 'admin@cycleaudit.in', NULL, 'Parisar', NULL, NULL, NULL, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'local', NULL, NULL, 1, '2026-05-19 07:07:07', '2026-05-19 07:07:07', '2026-05-19 07:07:07'),
(3, 'SURV-0003', 'Mithileish Waghmare', 'mithileish@cycleaudit.in', '9823000001', 'KSE', 'Male', 25, NULL, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'surveyor', 'local', NULL, NULL, 1, '2026-05-19 07:07:07', '2026-05-19 07:07:07', '2026-05-19 07:07:07'),
(4, 'SURV-0004', 'Harsh Patil', 'harsh@gmail.com', '6564756345', 'KSE', 'Male', 22, 'Handewadi, Pune', '$2y$10$sHdd6/dFlbGVigtC/kfmS./TD7drQee8IrHQTZxAdAAasg1UC/l0u', 'surveyor', 'local', NULL, 'http://localhost/segment-audit/assets/avatars/avatar_4_1779178945.jpg', 1, '2026-05-19 17:17:07', '2026-05-19 12:40:14', '2026-05-19 17:17:07'),
(9, 'SURV-0009', 'NICK', 'waghnikhil222@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, 'surveyor', 'google', '116844016547631687750', 'https://lh3.googleusercontent.com/a/ACg8ocL-twjU_NWPp4as40LEtrPor2WzHDfGDMQAl_ijHymR6WhNzZuDaQ=s96-c', 1, '2026-05-22 10:32:09', '2026-05-19 19:56:36', '2026-05-22 10:32:09'),
(11, 'SURV-0011', 'Nikhil Wagh', 'nikhilwagh1018@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, 'surveyor', 'google', '116002488960984290738', 'https://lh3.googleusercontent.com/a/ACg8ocIfiLgMM41Azs61YS7y9b3QT_s_rc6ZGT8FWV6LSj40PLdogxuw=s96-c', 1, '2026-05-20 10:35:13', '2026-05-19 22:16:14', '2026-05-20 10:35:13'),
(12, 'SURV-0012', 'Dhanaji Wagh', 'dhanajiwagh722@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, 'surveyor', 'google', '104264288371751221013', 'https://lh3.googleusercontent.com/a/ACg8ocItha5Va-l4fxe1OpjSyRhFGY6GcOcX8y3pqHNEzRlP_KyWkQ=s96-c', 1, '2026-05-20 10:25:50', '2026-05-20 10:25:50', '2026-05-20 10:25:50'),
(13, 'SURV-0013', 'Nikhil WAGH', 'nikhilwagh.comp@keystonesoe.in', NULL, NULL, NULL, NULL, NULL, NULL, 'surveyor', 'google', '104926278617322912387', 'https://lh3.googleusercontent.com/a/ACg8ocInxKnARMjDeUiMH77uyLRzU2OOXE8KeFY3M-7phe7qRasPDg=s96-c', 1, '2026-05-20 10:26:15', '2026-05-20 10:26:15', '2026-05-20 10:26:15');

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_sessions`
--
ALTER TABLE `audit_sessions`
  ADD CONSTRAINT `fk_audit_sessions_road` FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_audit_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `intersections`
--
ALTER TABLE `intersections`
  ADD CONSTRAINT `fk_intersections_audit` FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `obstructions`
--
ALTER TABLE `obstructions`
  ADD CONSTRAINT `fk_obstructions_audit` FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `roads`
--
ALTER TABLE `roads`
  ADD CONSTRAINT `fk_roads_creator` FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `segments`
--
ALTER TABLE `segments`
  ADD CONSTRAINT `fk_segments_road` FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `segment_audits`
--
ALTER TABLE `segment_audits`
  ADD CONSTRAINT `fk_sa_segment` FOREIGN KEY (`segment_id`) REFERENCES `segments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sa_session` FOREIGN KEY (`session_id`) REFERENCES `audit_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sa_surveyor` FOREIGN KEY (`surveyor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
SET FOREIGN_KEY_CHECKS=1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
