-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql100.infinityfree.com
-- Generation Time: Mar 12, 2026 at 05:12 PM
-- Server version: 11.4.10-MariaDB
-- PHP Version: 7.2.22

- MAKE SURE TO EDIT THE DATABASE BELOW. That's the only change needed before you can import.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `INSERT` <-- Put your database in here! Or else-
--

-- --------------------------------------------------------

--
-- Table structure for table `account_deletions`
--

CREATE TABLE `account_deletions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blocks`
--

CREATE TABLE `blocks` (
  `id` int(11) NOT NULL,
  `blocker_id` int(11) NOT NULL,
  `blocked_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `blocks`
--

INSERT INTO `blocks` (`id`, `blocker_id`, `blocked_id`, `created_at`) VALUES
(10, 8, 8, '2026-01-26 13:47:10');

-- --------------------------------------------------------

--
-- Table structure for table `channel_categories`
--

CREATE TABLE `channel_categories` (
  `id` int(11) NOT NULL,
  `community_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `position` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `edited_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `reply_to` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table structure for table `communities`
--

CREATE TABLE `communities` (
  `id` int(11) NOT NULL,
  `slug` varchar(128) DEFAULT NULL,
  `name` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(20) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `public_id` varchar(20) DEFAULT NULL,
  `visual_x` int(11) DEFAULT NULL,
  `visual_y` int(11) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `node_scale_min` float DEFAULT NULL,
  `node_scale_max` float DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `communities`
--

INSERT INTO `communities` (`id`, `slug`, `name`, `description`, `color`, `owner_id`, `created_at`, `public_id`, `visual_x`, `visual_y`, `logo`, `node_scale_min`, `node_scale_max`) VALUES
(19, 'simplicity', 'First.', 'Great job on getting so far! Edit these details in the \'communities\' table', NULL, 40, '2026-03-12 21:08:34', '1773349713609', 848, 464, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `community_audit`
--

CREATE TABLE `community_audit` (
  `id` int(11) NOT NULL,
  `community_id` int(11) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `actor_user` int(11) NOT NULL,
  `target_user` int(11) DEFAULT NULL,
  `target_message` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `community_audit`
--

INSERT INTO `community_audit` (`id`, `community_id`, `action`, `actor_user`, `target_user`, `target_message`, `reason`, `created_at`) VALUES
(1, 1, 'ban', 0, 0, NULL, 'bum', '2026-02-12 09:16:49'),
(2, 1, 'ban', 0, 0, NULL, 'lol', '2026-02-12 09:17:34'),
(3, 1, 'create_role', 8, NULL, NULL, 'created role New Role', '2026-02-12 11:58:03'),
(4, 1, 'assign_roles', 8, 13, 0, 'cleared roles', '2026-02-12 12:01:00'),
(5, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-12 12:01:15'),
(6, 1, 'assign_roles', 8, 13, 0, 'cleared roles', '2026-02-12 14:07:51'),
(7, 1, 'ban', 8, 17, NULL, 'messing with permissions.', '2026-02-12 14:08:29'),
(8, 1, 'ban', 8, 13, NULL, 'test', '2026-02-12 14:09:01'),
(9, 1, 'ban', 8, 17, NULL, '', '2026-02-12 15:24:49'),
(10, 1, 'assign_roles', 8, 6, 0, 'assigned roles', '2026-02-12 15:32:22'),
(11, 1, 'timeout', 8, 13, NULL, '', '2026-02-12 20:30:58'),
(12, 1, 'timeout', 8, 13, NULL, '', '2026-02-12 21:59:54'),
(13, 1, 'untimeout', 8, 13, NULL, 'untimeout by moderator', '2026-02-12 22:00:09'),
(14, 1, 'ban', 8, 13, NULL, '', '2026-02-12 22:00:21'),
(15, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-12 22:01:51'),
(16, 1, 'timeout', 8, 36, NULL, '', '2026-02-12 22:33:25'),
(17, 1, 'assign_roles', 8, 13, 0, 'cleared roles', '2026-02-13 11:33:29'),
(18, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-13 15:05:42'),
(19, 1, 'timeout', 8, 20, NULL, NULL, '2026-02-13 18:38:10'),
(20, 1, 'assign_role', 8, 8, NULL, NULL, '2026-02-13 18:38:53'),
(21, 1, 'assign_role', 8, 8, NULL, NULL, '2026-02-13 18:38:54'),
(22, 1, 'assign_role', 8, 8, NULL, NULL, '2026-02-13 18:38:54'),
(23, 1, 'assign_role', 8, 8, NULL, NULL, '2026-02-13 18:38:55'),
(24, 1, 'assign_role', 8, 8, NULL, NULL, '2026-02-13 18:38:55'),
(25, 1, 'timeout', 13, 17, NULL, NULL, '2026-02-13 18:43:06'),
(26, 1, 'untimeout', 13, 17, NULL, NULL, '2026-02-13 18:43:15'),
(27, 1, 'unban', 13, 17, NULL, NULL, '2026-02-13 18:43:19'),
(28, 1, 'ban', 13, 17, NULL, 'test', '2026-02-13 18:43:26'),
(29, 1, 'timeout', 13, 17, NULL, NULL, '2026-02-13 18:46:39'),
(30, 1, 'remove_role', 8, 13, NULL, NULL, '2026-02-13 18:55:38'),
(31, 1, 'remove_role', 8, 13, NULL, NULL, '2026-02-13 18:55:39'),
(32, 1, 'remove_role', 8, 13, NULL, NULL, '2026-02-13 18:55:40'),
(33, 1, 'remove_role', 8, 13, NULL, NULL, '2026-02-13 18:55:47'),
(34, 1, 'untimeout', 8, 9, NULL, NULL, '2026-02-13 18:56:05'),
(35, 1, 'timeout', 8, 8, NULL, NULL, '2026-02-15 15:07:16'),
(36, 1, 'timeout', 8, 8, NULL, '', '2026-02-15 16:10:07'),
(37, 1, 'untimeout', 8, 8, NULL, 'untimeout by moderator', '2026-02-15 16:10:26'),
(38, 1, 'timeout', 8, 8, NULL, '', '2026-02-15 16:32:24'),
(39, 1, 'timeout', 8, 6, NULL, '', '2026-02-15 16:32:29'),
(40, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-15 16:33:17'),
(41, 1, 'untimeout', 8, 8, NULL, 'untimeout by moderator', '2026-02-15 16:33:58'),
(42, 1, 'timeout', 8, 17, NULL, '', '2026-02-15 16:36:09'),
(43, 1, 'assign_roles', 8, 13, 0, 'cleared roles', '2026-02-15 16:36:49'),
(44, 1, 'timeout', 8, 13, NULL, '', '2026-02-15 16:36:58'),
(45, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-15 16:38:33'),
(46, 1, 'edit', 6, 6, 0, NULL, '2026-02-15 18:08:57'),
(47, 1, 'timeout', 13, 20, NULL, NULL, '2026-02-16 08:53:14'),
(48, 1, 'untimeout', 13, 20, NULL, NULL, '2026-02-16 08:53:28'),
(49, 1, 'unban', 13, 17, NULL, NULL, '2026-02-16 08:55:12'),
(50, 1, 'assign_roles', 8, 17, 0, 'assigned roles', '2026-02-16 10:23:48'),
(51, 1, 'assign_roles', 8, 32, 0, 'assigned roles', '2026-02-16 11:16:38'),
(52, 1, 'assign_roles', 8, 32, 0, 'cleared roles', '2026-02-16 11:19:54'),
(53, 1, 'assign_roles', 8, 6, 0, 'assigned roles', '2026-02-16 11:26:08'),
(54, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-16 17:54:46'),
(55, 1, 'edit', 6, 6, 0, NULL, '2026-02-17 08:10:05'),
(56, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-17 10:28:14'),
(57, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-17 10:56:59'),
(58, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-17 10:57:12'),
(59, 1, 'timeout', 8, 13, NULL, '', '2026-02-17 10:58:40'),
(60, 1, 'timeout', 8, 13, NULL, '', '2026-02-17 10:59:23'),
(61, 1, 'timeout', 13, 32, NULL, '', '2026-02-17 11:00:58'),
(62, 2, 'edit', 13, 13, 0, NULL, '2026-02-17 10:02:37'),
(63, 1, 'assign_roles', 13, 32, 0, 'assigned roles', '2026-02-17 11:05:30'),
(64, 1, 'assign_roles', 13, 32, 0, 'cleared roles', '2026-02-17 11:15:48'),
(65, 1, 'timeout', 8, 13, NULL, '', '2026-02-17 11:16:37'),
(66, 1, 'timeout', 8, 13, NULL, '', '2026-02-17 11:16:48'),
(67, 1, 'assign_roles', 13, 17, 0, 'assigned roles', '2026-02-17 11:35:00'),
(68, 1, 'assign_roles', 13, 17, 0, 'assigned roles', '2026-02-17 11:35:06'),
(69, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-17 11:38:46'),
(70, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-17 11:38:55'),
(71, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-17 11:39:24'),
(72, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-17 11:39:24'),
(73, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-17 11:39:24'),
(74, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-17 11:39:24'),
(75, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-17 11:39:50'),
(76, 1, 'reorder_roles', 8, NULL, 0, 'reordered roles', '2026-02-17 21:58:22'),
(77, 1, 'reorder_roles', 8, NULL, 0, 'reordered roles', '2026-02-17 21:58:28'),
(78, 1, 'update_role', 8, NULL, 0, 'updated role', '2026-02-21 14:40:27'),
(79, 1, 'reorder_roles', 8, NULL, 0, 'reordered roles', '2026-02-21 14:40:35'),
(80, 1, 'update_role', 8, NULL, 0, 'updated role', '2026-02-21 14:40:45'),
(81, 1, 'update_role', 8, NULL, 0, 'updated role', '2026-02-21 14:40:54'),
(82, 1, 'update_role', 8, NULL, 0, 'updated role', '2026-02-21 14:41:00'),
(83, 1, 'reorder_roles', 8, NULL, 0, 'reordered roles', '2026-02-23 09:27:07'),
(84, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-23 16:15:21'),
(85, 1, 'edit', 6, 6, 0, NULL, '2026-02-24 15:04:23'),
(86, 1, 'edit', 8, 8, 0, NULL, '2026-02-25 13:37:24'),
(87, 1, 'edit', 8, 8, 0, NULL, '2026-02-25 13:52:57'),
(88, 1, 'edit', 8, 8, 0, NULL, '2026-02-25 14:29:11'),
(89, 1, 'edit', 8, 8, 0, NULL, '2026-02-25 14:38:33'),
(90, 1, 'add_role', 8, 38, 0, 'assigned role via admin UI', '2026-02-25 21:30:20'),
(91, 1, 'edit', 8, 8, 0, NULL, '2026-02-25 21:45:06'),
(92, 1, 'edit', 8, 8, 0, NULL, '2026-02-26 13:44:26'),
(93, 1, 'edit', 8, 8, 0, NULL, '2026-02-26 14:24:47'),
(94, 1, 'edit', 8, 8, 0, NULL, '2026-02-26 14:24:59'),
(95, 1, 'edit', 8, 8, 0, NULL, '2026-02-26 20:05:01'),
(96, 1, 'edit', 6, 6, 0, NULL, '2026-02-26 20:45:59'),
(97, 1, 'edit', 6, 6, 0, NULL, '2026-02-27 06:53:35'),
(98, 1, 'add_role', 8, 37, 0, 'assigned role via admin UI', '2026-02-27 08:00:08'),
(99, 1, 'edit', 8, 8, 0, NULL, '2026-02-27 13:35:15'),
(100, 1, 'assign_roles', 8, 8, 0, 'assigned roles', '2026-02-27 19:12:02'),
(101, 1, 'assign_roles', 8, 13, 0, 'assigned roles', '2026-02-27 22:49:27'),
(102, 1, 'edit', 8, 8, 0, NULL, '2026-02-28 15:55:43'),
(103, 1, 'edit', 8, 8, 0, NULL, '2026-02-28 15:55:51'),
(104, 1, 'edit', 8, 8, 0, NULL, '2026-02-28 15:55:59');

-- --------------------------------------------------------

--
-- Table structure for table `community_bans`
--

CREATE TABLE `community_bans` (
  `id` int(11) NOT NULL,
  `community_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(128) DEFAULT NULL,
  `banned_by` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `until_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `community_blocks`
--

CREATE TABLE `community_blocks` (
  `id` int(11) NOT NULL,
  `community_id` int(11) NOT NULL,
  `code` varchar(120) NOT NULL,
  `block_type` varchar(80) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `config_json` text DEFAULT NULL,
  `visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `community_block_messages`
--

CREATE TABLE `community_block_messages` (
  `id` bigint(20) NOT NULL,
  `block_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(200) DEFAULT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `community_block_state`
--

CREATE TABLE `community_block_state` (
  `id` int(11) NOT NULL,
  `block_id` int(11) NOT NULL,
  `scope` enum('global','user','block') NOT NULL DEFAULT 'global',
  `scope_id` varchar(128) DEFAULT NULL,
  `key` varchar(150) NOT NULL,
  `value_json` longtext DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `community_members`
--

CREATE TABLE `community_members` (
  `community_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `community_member_roles`
--

CREATE TABLE `community_member_roles` (
  `id` int(11) NOT NULL,
  `community_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `assigned_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `community_member_roles`
--

INSERT INTO `community_member_roles` (`id`, `community_id`, `user_id`, `role_id`, `assigned_at`) VALUES
(3, 1, 9, 5, '2026-02-04 09:29:12'),
(6, 2, 8, 7, '2026-02-04 09:29:12'),
(30, 1, 6, 1, '2026-02-16 10:26:08'),
(31, 1, 6, 2, '2026-02-16 10:26:08'),
(32, 1, 6, 4, '2026-02-16 10:26:08'),
(43, 1, 17, 4, '2026-02-17 10:35:06'),
(44, 1, 17, 6, '2026-02-17 10:35:06'),
(57, 1, 38, 5, '2026-02-25 21:30:20'),
(58, 1, 37, 6, '2026-02-27 08:00:08'),
(59, 1, 8, 1, '2026-02-27 18:12:02'),
(60, 1, 8, 2, '2026-02-27 18:12:02'),
(61, 1, 8, 3, '2026-02-27 18:12:02'),
(62, 1, 8, 8, '2026-02-27 18:12:02'),
(63, 1, 13, 4, '2026-02-27 21:49:27'),
(64, 3, 6, 11, '2026-03-12 09:34:11');

-- --------------------------------------------------------

--
-- Table structure for table `community_roles`
--

CREATE TABLE `community_roles` (
  `id` int(11) NOT NULL,
  `community_id` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `badge` varchar(32) DEFAULT NULL,
  `color` varchar(20) DEFAULT NULL,
  `is_owner` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_admin` tinyint(1) DEFAULT 0,
  `can_delete_messages` tinyint(1) DEFAULT 0,
  `can_timeout` tinyint(1) DEFAULT 0,
  `can_ban` tinyint(1) DEFAULT 0,
  `can_assign_roles` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit_channels` tinyint(1) DEFAULT 0,
  `can_view_locked` tinyint(1) DEFAULT 0,
  `priority` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `community_roles`
--

INSERT INTO `community_roles` (`id`, `community_id`, `name`, `badge`, `color`, `is_owner`, `created_at`, `is_admin`, `can_delete_messages`, `can_timeout`, `can_ban`, `can_assign_roles`, `can_edit_channels`, `can_view_locked`, `priority`) VALUES
(1, 1, 'Owner', '★', '#173f9a', 1, '2026-02-03 10:51:49', 1, 1, 1, 1, 1, 1, 1, 7),
(2, 1, 'Admin', '☆', '#2b6fb2', 0, '2026-02-03 10:51:49', 1, 1, 1, 1, 1, 1, 1, 6),
(3, 1, 'Moderator', '✦', '#4aa3ff', 0, '2026-02-03 10:51:49', 0, 1, 1, 1, 0, 0, 1, 3),
(4, 1, 'Member', '', '#9bbcff', 0, '2026-02-03 10:51:49', 0, 0, 0, 0, 0, 0, 0, 2),
(5, 1, 'Battty Bat!!', '꩜', '#32a852', 0, '2026-02-03 11:10:58', 0, 0, 0, 0, 0, 0, 0, 5),
(6, 1, 'The Flood', NULL, '#c4761d', 0, '2026-02-03 11:29:49', 0, 0, 0, 0, 0, 0, 0, 1),
(7, 2, 'Mafia', NULL, NULL, 0, '2026-02-03 12:48:30', 1, 1, 0, 0, 0, 0, 1, 1729),
(8, 1, 'New Role', '&', '#004285', 0, '2026-02-12 11:58:03', 0, 0, 0, 0, 0, 0, 0, 4),
(9, 3, 'Member', NULL, NULL, 0, '2026-03-04 09:06:36', 0, 0, 0, 0, 0, 0, 0, 0),
(10, 3, 'Moderator', '✦', NULL, 0, '2026-03-04 09:06:36', 0, 1, 1, 1, 0, 1, 1, 0),
(11, 3, 'Admin', '★', NULL, 0, '2026-03-04 09:06:36', 1, 1, 1, 1, 0, 1, 1, 0),
(12, 5, 'Member', NULL, NULL, 0, '2026-03-04 09:06:58', 0, 0, 0, 0, 0, 0, 0, 0),
(13, 5, 'Moderator', '✦', NULL, 0, '2026-03-04 09:06:58', 0, 1, 1, 1, 0, 1, 1, 0),
(14, 4, 'Member', NULL, NULL, 0, '2026-03-04 09:06:58', 0, 0, 0, 0, 0, 0, 0, 0),
(15, 4, 'Moderator', '✦', NULL, 0, '2026-03-04 09:06:58', 0, 1, 1, 1, 0, 1, 1, 0),
(16, 5, 'Admin', '★', NULL, 0, '2026-03-04 09:06:58', 1, 1, 1, 1, 0, 1, 1, 0),
(17, 4, 'Admin', '★', NULL, 0, '2026-03-04 09:06:58', 1, 1, 1, 1, 0, 1, 1, 0),
(18, 6, 'Member', NULL, NULL, 0, '2026-03-04 09:06:58', 0, 0, 0, 0, 0, 0, 0, 0),
(19, 6, 'Moderator', '✦', NULL, 0, '2026-03-04 09:06:58', 0, 1, 1, 1, 0, 1, 1, 0),
(20, 6, 'Admin', '★', NULL, 0, '2026-03-04 09:06:58', 1, 1, 1, 1, 0, 1, 1, 0),
(21, 7, 'Member', NULL, NULL, 0, '2026-03-04 09:07:05', 0, 0, 0, 0, 0, 0, 0, 0),
(22, 7, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:05', 0, 1, 1, 1, 0, 1, 1, 0),
(23, 7, 'Admin', '★', NULL, 0, '2026-03-04 09:07:05', 1, 1, 1, 1, 0, 1, 1, 0),
(24, 8, 'Member', NULL, NULL, 0, '2026-03-04 09:07:08', 0, 0, 0, 0, 0, 0, 0, 0),
(25, 8, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:08', 0, 1, 1, 1, 0, 1, 1, 0),
(26, 8, 'Admin', '★', NULL, 0, '2026-03-04 09:07:08', 1, 1, 1, 1, 0, 1, 1, 0),
(27, 9, 'Member', NULL, NULL, 0, '2026-03-04 09:07:12', 0, 0, 0, 0, 0, 0, 0, 0),
(28, 9, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:12', 0, 1, 1, 1, 0, 1, 1, 0),
(29, 9, 'Admin', '★', NULL, 0, '2026-03-04 09:07:12', 1, 1, 1, 1, 0, 1, 1, 0),
(30, 10, 'Member', NULL, NULL, 0, '2026-03-04 09:07:15', 0, 0, 0, 0, 0, 0, 0, 0),
(31, 10, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:15', 0, 1, 1, 1, 0, 1, 1, 0),
(32, 10, 'Admin', '★', NULL, 0, '2026-03-04 09:07:15', 1, 1, 1, 1, 0, 1, 1, 0),
(33, 11, 'Member', NULL, NULL, 0, '2026-03-04 09:07:19', 0, 0, 0, 0, 0, 0, 0, 0),
(34, 11, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:19', 0, 1, 1, 1, 0, 1, 1, 0),
(35, 11, 'Admin', '★', NULL, 0, '2026-03-04 09:07:19', 1, 1, 1, 1, 0, 1, 1, 0),
(36, 12, 'Member', NULL, NULL, 0, '2026-03-04 09:07:23', 0, 0, 0, 0, 0, 0, 0, 0),
(37, 12, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:23', 0, 1, 1, 1, 0, 1, 1, 0),
(38, 12, 'Admin', '★', NULL, 0, '2026-03-04 09:07:23', 1, 1, 1, 1, 0, 1, 1, 0),
(39, 13, 'Member', NULL, NULL, 0, '2026-03-04 09:07:26', 0, 0, 0, 0, 0, 0, 0, 0),
(40, 13, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:26', 0, 1, 1, 1, 0, 1, 1, 0),
(41, 13, 'Admin', '★', NULL, 0, '2026-03-04 09:07:26', 1, 1, 1, 1, 0, 1, 1, 0),
(42, 14, 'Member', NULL, NULL, 0, '2026-03-04 09:07:29', 0, 0, 0, 0, 0, 0, 0, 0),
(43, 14, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:29', 0, 1, 1, 1, 0, 1, 1, 0),
(44, 14, 'Admin', '★', NULL, 0, '2026-03-04 09:07:29', 1, 1, 1, 1, 0, 1, 1, 0),
(45, 15, 'Member', NULL, NULL, 0, '2026-03-04 09:07:32', 0, 0, 0, 0, 0, 0, 0, 0),
(46, 15, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:32', 0, 1, 1, 1, 0, 1, 1, 0),
(47, 15, 'Admin', '★', NULL, 0, '2026-03-04 09:07:32', 1, 1, 1, 1, 0, 1, 1, 0),
(48, 16, 'Member', NULL, NULL, 0, '2026-03-04 09:07:36', 0, 0, 0, 0, 0, 0, 0, 0),
(49, 16, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:36', 0, 1, 1, 1, 0, 1, 1, 0),
(50, 16, 'Admin', '★', NULL, 0, '2026-03-04 09:07:36', 1, 1, 1, 1, 0, 1, 1, 0),
(51, 17, 'Member', NULL, NULL, 0, '2026-03-04 09:07:40', 0, 0, 0, 0, 0, 0, 0, 0),
(52, 17, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:40', 0, 1, 1, 1, 0, 1, 1, 0),
(53, 17, 'Admin', '★', NULL, 0, '2026-03-04 09:07:40', 1, 1, 1, 1, 0, 1, 1, 0),
(54, 18, 'Member', NULL, NULL, 0, '2026-03-04 09:07:44', 0, 0, 0, 0, 0, 0, 0, 0),
(55, 18, 'Moderator', '✦', NULL, 0, '2026-03-04 09:07:44', 0, 1, 1, 1, 0, 1, 1, 0),
(56, 18, 'Admin', '★', NULL, 0, '2026-03-04 09:07:44', 1, 1, 1, 1, 0, 1, 1, 0),
(57, 19, 'Member', NULL, NULL, 0, '2026-03-12 21:08:34', 0, 0, 0, 0, 0, 0, 0, 0),
(58, 19, 'Moderator', '✦', NULL, 0, '2026-03-12 21:08:34', 0, 1, 1, 1, 0, 1, 1, 0),
(59, 19, 'Admin', '★', NULL, 0, '2026-03-12 21:08:34', 1, 1, 1, 1, 0, 1, 1, 0);

-- --------------------------------------------------------

--
-- Table structure for table `community_timeouts`
--

CREATE TABLE `community_timeouts` (
  `id` int(11) NOT NULL,
  `community_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `actor_user` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `until_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dm_messages`
--

CREATE TABLE `dm_messages` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(128) NOT NULL,
  `target_user_id` int(11) NOT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `edited_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `reply_to` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dm_typing`
--

CREATE TABLE `dm_typing` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(128) NOT NULL,
  `target_user_id` int(11) NOT NULL,
  `typing_until` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `friendships`
--

CREATE TABLE `friendships` (
  `id` int(11) NOT NULL,
  `user_a` int(11) NOT NULL,
  `user_b` int(11) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'requested',
  `initiator` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `requested_kind` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `modmail`
--

CREATE TABLE `modmail` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `admin_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `modmail`
--

INSERT INTO `modmail` (`id`, `title`, `subtitle`, `body`, `admin_name`, `created_at`) VALUES
(1, 'Welcome to the Site!', 'Rules and Top tips', 'Hey guys!! Motylo is now becoming a fully functional chat website, and you\'re welcome to invite non-troublesome new users!\n\nJust some ground rules...\n\n1- No slurring!! I don\'t expect it but lets keep our language clean!\n\n2- Don\'t be overly freaky in chat... I know what you guys are like but try and avoid gross sexual commentary\n\n3- Don\'t have too many tabs open!! I can\'t check this but try and keep it to 1 open at a time, 2 at maximum if you really have too.\n\n\nTop tips!\n\nClick the UPLOAD FILE button and THEN click the avatar button to get your own profile picture. \n\nYou need to give the site access to your microphone in voice chat if you want to hear other people. Then just mute yourself after. \n\nWorried about your personal data? Well this site doesn\'t collect anything! We only send your messages, display images uploaded to your pfp and protect your account with your password. \n\nWE DO NOT COLLECT ANYTHING ELSE!!!\n\nSorry for the long message, apart from all that, have a great time!\n\nSolos, Site Administrator\n\nI walk by every twinkling of the eye.', 'Site Admin', '2026-01-13 01:30:16');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(64) NOT NULL,
  `source_user_id` int(11) DEFAULT NULL,
  `message` varchar(255) NOT NULL,
  `context` varchar(64) DEFAULT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `important` int(11) NOT NULL,
  `ref_code` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `private_messages`
--

CREATE TABLE `private_messages` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `edited_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `reply_to` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

--
-- Dumping data for table `private_messages`
--

INSERT INTO `private_messages` (`id`, `room_id`, `user_id`, `username`, `message`, `created_at`, `edited_at`, `deleted_at`, `deleted_by`, `reply_to`) VALUES
(7083, 25, 40, 'tester', 'Hi there', '2026-03-12 21:08:43', NULL, NULL, NULL, NULL),
(7084, 25, 40, 'tester', 'Great job!', '2026-03-12 21:08:48', NULL, NULL, NULL, NULL),
(7085, 25, 40, 'tester', 'Feel free to delete this community in the database, or transfer ownership to yourself so you can access admin!', '2026-03-12 21:09:10', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `private_rooms`
--

CREATE TABLE `private_rooms` (
  `id` int(11) NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `community_id` int(11) DEFAULT NULL,
  `required_role_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `is_hidden` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

--
-- Dumping data for table `private_rooms`
--

INSERT INTO `private_rooms` (`id`, `code`, `name`, `community_id`, `required_role_id`, `category_id`, `is_hidden`) VALUES
(25, 'c0ff87b1491', 'general', 19, NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `private_typing`
--

CREATE TABLE `private_typing` (
  `room_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `typing_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci ROW_FORMAT=DYNAMIC;

--
-- Dumping data for table `private_typing`
--

INSERT INTO `private_typing` (`room_id`, `user_id`, `username`, `typing_until`) VALUES
(25, 40, 'tester', '2026-03-12 21:09:12');

-- --------------------------------------------------------

--
-- Table structure for table `push_subscriptions`
--

CREATE TABLE `push_subscriptions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `player_id` varchar(255) NOT NULL,
  `device_info` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `community_id` int(11) DEFAULT NULL,
  `message_id` int(11) DEFAULT NULL,
  `reporter_user_id` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(30) NOT NULL,
  `color` varchar(20) NOT NULL,
  `badge` varchar(5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `color`, `badge`) VALUES
(1, 'Site Administrator', '#0c18a6', '★⋆˙'),
(2, 'Site Moderator', '#00fbff', '✧'),
(3, 'member', '#9bbcff', '');

-- --------------------------------------------------------

--
-- Table structure for table `uploaded_images`
--

CREATE TABLE `uploaded_images` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `size_bytes` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `uploaded_images`
--

INSERT INTO `uploaded_images` (`id`, `filename`, `original_name`, `user_id`, `size_bytes`, `created_at`) VALUES
(78, 'f43133e04d6186bbf765.png', 'Screenshot 2026-02-26 at 13.44.57.png', 8, 102851, '2026-02-26 14:10:17'),
(79, '1e8b10f3591fed249429.png', 'Screenshot 2025-12-15 at 20.30.43.png', 6, 1633756, '2026-02-26 16:39:55'),
(80, '341cecade21029aa6dfc.png', 'Screenshot 2026-02-26 at 13.47.44.png', 8, 489461, '2026-02-26 19:33:57'),
(81, 'bb09dde5a6c0fbcebed5.jpg', '8ccd328b6adc29e544c36d4acf6e92bd.jpg', 8, 137648, '2026-02-26 19:35:00'),
(82, '5b32796530387161d86f.png', 'Screenshot 2026-02-26 at 19.35.25.png', 8, 21520, '2026-02-26 19:35:41'),
(83, 'a323fcfe4d3a299875a4.png', 'Screenshot 2025-08-10 at 15.10.36.png', 8, 65377, '2026-02-26 19:47:57'),
(84, '3e9cb4a8a738f7646ce4.png', 'Screenshot 2025-09-18 at 14.15.16.png', 8, 94362, '2026-02-26 19:50:27'),
(85, 'fc71111595b9b26409e6.png', 'Screenshot 2026-02-26 at 13.44.57.png', 8, 102851, '2026-02-26 19:50:53'),
(86, '2edd36b23fccdab70bf0.png', 'Screenshot 2026-02-25 at 13.24.14.png', 8, 929784, '2026-02-26 19:53:08'),
(87, 'f866706df77cbd8ace0d.png', 'Screenshot 2026-02-25 at 14.22.51.png', 8, 69384, '2026-02-26 19:53:21'),
(88, '270644c585d4a14ae7e7.png', 'Screenshot 2026-02-26 at 15.36.57.png', 8, 1345966, '2026-02-26 19:56:03'),
(89, '88c90057554dae496891.png', 'Screenshot 2025-08-10 at 16.56.46.png', 8, 175575, '2026-02-26 19:57:19'),
(90, '2c653198a909612f247e.png', 'Screenshot 2025-12-15 at 20.30.43.png', 6, 1633756, '2026-02-26 22:09:15'),
(91, '3d8e11b9ad2f003924cd.png', 'Screenshot 2025-11-23 at 08.13.56.png', 6, 1817343, '2026-02-26 22:10:14'),
(92, 'ad111412f8ab4240b8ab.png', 'Screenshot 2025-11-10 at 09.27.00.png', 8, 860130, '2026-02-26 22:32:10'),
(93, 'a5ee2c8ee8997974e4ca.png', 'Screenshot 2025-12-15 at 20.22.09.png', 6, 1473820, '2026-02-27 07:42:30'),
(94, '937510db015fb3eb2d42.png', 'a.PNG', 9, 4333, '2026-02-27 12:48:23'),
(95, 'b5d0e60e101b46ffd785.png', 'Screenshot 2026-02-27 at 12.51.33 pm.png', 8, 1168775, '2026-02-27 12:54:36'),
(96, 'c73f2778cdcd3d10971c.jpg', 'Screenshot 2025-10-12 at 13.07.10.jpg', 8, 215186, '2026-02-27 13:26:17'),
(97, 'cf4cdb4cf0926c6814f0.jpg', '0ed99a3df1d71d82d9736197d86af390.jpg', 8, 150540, '2026-02-27 13:27:29'),
(98, 'b6927bc178a6320c279b.jpg', '0d4f4ec07419c3abf76990e4fa0e311c.jpg', 8, 496146, '2026-02-27 13:33:08'),
(99, '2c6f5b9f605f4521afd9.jpg', '33fead29603c4168b5cfd54fb8d54e91.jpg', 8, 92237, '2026-02-27 13:33:41'),
(100, '0270ba0a340eb64c5fcf.jpg', '59626bc4b67d12a2fb5567bd71f72b07.jpg', 8, 135558, '2026-02-27 13:34:07'),
(101, 'a91860ce203013ee76fb.jpg', '2bea10343fa355021ee6efb36868512b.jpg', 8, 184614, '2026-02-27 13:37:41'),
(102, 'dc098ef951ecc5e3b063.jpg', '40078626e5f8809a05f5e364af9c6fee.jpg', 8, 248770, '2026-02-27 13:41:02'),
(103, 'ba67a1a4612e63452477.jpg', 'bd57bd7e6663dcc57da2c78bd510854e.jpg', 8, 119131, '2026-02-27 13:43:19'),
(104, '55bbdece6850d9c89e18.jpg', 'ef1c058400f4ae84fd21359608948e0d.jpg', 8, 165596, '2026-02-27 13:46:35'),
(105, '1a310fc47da97d2e7f2a.jpg', '32bba9722dbae8ac6ef9064af235662f.jpg', 8, 162732, '2026-02-27 14:07:58'),
(106, 'cf9b32407e97ae378abb.jpg', 'e0f626dc4d5914f994f8de8051e1ad3f.jpg', 8, 233718, '2026-02-27 14:08:26'),
(107, '495d49205f94fb20fc71.jpg', 'b1608eb52dbaa1fb0c2f2a9deb2b0349.jpg', 8, 151262, '2026-02-27 14:08:42'),
(108, 'fe7e46ec0589d30120aa.jpg', 'c082f91fc582635be96539c4b0355faa.jpg', 8, 161897, '2026-02-27 14:08:50'),
(109, '8a1c28a0d43ff9953516.webp', '5662136_900_1350_127452.webp', 8, 127452, '2026-02-27 14:08:59'),
(110, '3f1e0402653eca1ebd05.png', 'Screenshot 2025-11-15 at 10.14.39.png', 8, 1088465, '2026-02-27 17:43:46'),
(111, 'ea59f6db188fdd928258.jpg', '0dc7d4e6a838b1008cb22fa269d155a5.jpg', 8, 165088, '2026-02-27 19:01:27'),
(112, '7fae8d0fce6375a9056c.jpg', '32a36cc798c840ca001eab8161e06fbe.jpg', 8, 70597, '2026-02-27 21:22:58'),
(113, '43b215c4fcc2dd75f6e4.jpg', 'bd57bd7e6663dcc57da2c78bd510854e.jpg', 8, 119131, '2026-02-27 21:41:28'),
(114, 'fee2bf04245c1ffb7a29.jpg', '4a7ef3693210eeb210518e36f2b6185e.jpg', 8, 80683, '2026-02-27 21:45:46'),
(115, '260cdf5116bd05de0fad.png', 'Screenshot 2025-12-15 at 20.30.43.png', 6, 1633756, '2026-02-28 17:27:38'),
(116, '9c11a2b9890bd21b4e42.png', 'Screenshot 2025-12-10 at 12.06.51.png', 8, 1402306, '2026-02-28 20:01:09'),
(117, '7408e6aa4fdd355633f1.jpg', '0ed99a3df1d71d82d9736197d86af390.jpg', 8, 150540, '2026-02-28 22:01:05'),
(118, '51bb13c244aeab5cb3cf.png', 'Screenshot 2025-12-13 at 08.06.06.png', 6, 1823398, '2026-03-01 09:26:20'),
(119, 'b47695655f553d4cfb27.png', 'Screenshot 2026-03-01 at 9.32.10 am.png', 6, 217201, '2026-03-01 09:32:58'),
(120, '2011f10b67c1d3b2b055.jpg', '0dc7d4e6a838b1008cb22fa269d155a5.jpg', 8, 165088, '2026-03-01 15:14:22'),
(121, '27031efb382c8fcb2c17.png', 'Screenshot 2025-12-11 at 10.33.39.png', 6, 1053766, '2026-03-01 16:14:40'),
(122, '071de26e5e64fa723b43.webp', '5662136_900_1350_127452.webp', 8, 127452, '2026-03-01 18:35:34'),
(123, '74a7e6669c660b7ae199.png', 'Screenshot 2025-11-10 at 08.00.50.png', 8, 2010808, '2026-03-01 19:08:32'),
(124, '7bff13556e31a69f205d.png', 'Screenshot 2025-11-12 at 12.28.47.png', 8, 1476600, '2026-03-01 19:17:54'),
(125, '80fbbee49c7d2083f081.png', 'Screenshot 2025-11-22 at 14.49.58.png', 8, 927257, '2026-03-02 19:58:32'),
(126, '31a26af39bd95c84f942.png', 'Screenshot 2025-11-22 at 17.51.39.png', 8, 154418, '2026-03-03 13:09:20'),
(127, '5778a15121882ae15fb5.png', 'Screenshot 2026-03-03 at 13.10.55.png', 8, 33273, '2026-03-03 13:11:07'),
(128, 'e36c41e3526d721e9436.jpg', 'IMG_3731 Large.jpeg', 8, 215722, '2026-03-03 13:17:37'),
(129, '4fc9a4560584818372d8.jpg', 'Screenshot 2025-10-12 at 13.07.10.jpg', 8, 215186, '2026-03-03 14:05:43'),
(130, '1fc96e3761a40224fb2a.jpg', 'Screenshot 2025-10-12 at 13.07.10.jpg', 8, 215186, '2026-03-03 14:27:25'),
(131, '491d6605b049a218ca66.png', 'Screenshot 2026-02-01 at 14.47.15.png', 6, 875021, '2026-03-12 09:35:45');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `role` varchar(32) NOT NULL DEFAULT 'member',
  `color` varchar(16) DEFAULT NULL,
  `timeout_until` datetime DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `bio` mediumtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `typing` tinyint(4) DEFAULT 0,
  `auth_token` varchar(128) DEFAULT NULL,
  `last_typing` datetime DEFAULT NULL,
  `typing_until` datetime DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `role_id` int(10) UNSIGNED DEFAULT 3,
  `password` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `role`, `color`, `timeout_until`, `password_hash`, `email`, `full_name`, `bio`, `created_at`, `typing`, `auth_token`, `last_typing`, `typing_until`, `avatar`, `role_id`, `password`) VALUES
(1, 'alpha_user', 'member', NULL, NULL, '$2a$10$tJ08Jm.Qx5f/xG/bC7L6o.Tq9.8Hj1rT7oF1w9.Gj2iC4.R6k0O2m', 'alpha.user@example.com', 'Alexander Poe', 'Avid landscape photographer and travel enthusiast.', '2025-12-09 02:28:35', 0, '', NULL, NULL, NULL, 3, '0'),
(2, 'beta_gal', 'member', NULL, NULL, '$2a$10$wK9gNl.Pz7o/wJ/jD8m7o.Sq0.3Ie4sV3oO8t0.E2eC5.V8l0N3n', 'beta.gal@webmail.com', 'Beatrice Gale', 'Graphic designer with a passion for minimalistic aesthetics.', '2025-12-09 02:28:35', 0, NULL, NULL, NULL, NULL, 3, '0'),
(3, 'charlie_dev', 'member', NULL, NULL, '$2a$10$lA6sOm.Rz8p/vI/cD1k8p.Uu0.4Jg5tX2xX7u.F3f6wA0M4o7P5s', 'charlie@devnet.org', 'Charles Davis', 'Software developer learning new frameworks every day.', '2025-12-09 02:28:35', 0, NULL, NULL, NULL, NULL, 3, '0'),
(4, 'delta_art', 'member', NULL, NULL, '$2a$10$hB3tPu.Sx1k/yL/eF2n9q.Vv1.5Kj6uY3yY6v.G4g7xP1Q5r8T6a', 'delta.artist@artzone.net', 'Delilah Adams', 'Watercolor painter. Inspired by nature and mythology.', '2025-12-09 02:28:35', 0, NULL, NULL, NULL, NULL, 3, '0'),
(5, 'echo_writer', 'member', NULL, NULL, '$2a$10$mE5uRw.Ty2l/zM/fG3o0r.Ww2.6Lh7vW4zZ9w.H5h8yQ2S6s9U7b', 'echo_writer@email.co', 'Elias West', 'Historical fiction author and part-time coffee connoisseur.', '2025-12-09 02:28:35', 0, NULL, NULL, NULL, NULL, 3, '0'),
(40, 'tester', 'member', NULL, NULL, '', '', NULL, NULL, '2026-03-12 21:07:42', 0, 'ecb73a287fb2e4e3d4262cd786937f971ba8b0e766793144124eb15db31b65a0', NULL, NULL, NULL, 3, '$2y$10$xwS2WFG.ayYgJqggeRrK7uUrYr/Dat5fSchoMfWQbU2vRPGjX0mO.');

-- --------------------------------------------------------

--
-- Table structure for table `user_rooms`
--

CREATE TABLE `user_rooms` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `room_code` varchar(128) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_deletions`
--
ALTER TABLE `account_deletions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `blocks`
--
ALTER TABLE `blocks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_block` (`blocker_id`,`blocked_id`);

--
-- Indexes for table `channel_categories`
--
ALTER TABLE `channel_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_cat_name` (`community_id`,`name`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `idx_chat_reply_to` (`reply_to`);

--
-- Indexes for table `communities`
--
ALTER TABLE `communities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `public_id` (`public_id`);

--
-- Indexes for table `community_audit`
--
ALTER TABLE `community_audit`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `community_bans`
--
ALTER TABLE `community_bans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `community_id` (`community_id`);

--
-- Indexes for table `community_blocks`
--
ALTER TABLE `community_blocks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `community_id` (`community_id`,`code`);

--
-- Indexes for table `community_block_messages`
--
ALTER TABLE `community_block_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `block_id` (`block_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `community_block_state`
--
ALTER TABLE `community_block_state`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `block_key_scope` (`block_id`,`scope`,`scope_id`,`key`) USING HASH;

--
-- Indexes for table `community_members`
--
ALTER TABLE `community_members`
  ADD PRIMARY KEY (`community_id`,`user_id`);

--
-- Indexes for table `community_member_roles`
--
ALTER TABLE `community_member_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_member_role` (`community_id`,`user_id`,`role_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `community_id` (`community_id`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `community_roles`
--
ALTER TABLE `community_roles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `community_id` (`community_id`);

--
-- Indexes for table `community_timeouts`
--
ALTER TABLE `community_timeouts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `community_id` (`community_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `dm_messages`
--
ALTER TABLE `dm_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `target_user_id` (`target_user_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `dm_typing`
--
ALTER TABLE `dm_typing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_user_target` (`user_id`,`target_user_id`);

--
-- Indexes for table `friendships`
--
ALTER TABLE `friendships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_pair` (`user_a`,`user_b`);

--
-- Indexes for table `modmail`
--
ALTER TABLE `modmail`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `is_read` (`is_read`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `source_user_id` (`source_user_id`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_user_type` (`user_id`,`type`),
  ADD KEY `idx_user_isread_created` (`user_id`,`is_read`,`created_at`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `ref_code` (`ref_code`);

--
-- Indexes for table `private_messages`
--
ALTER TABLE `private_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_private_reply_to` (`reply_to`);

--
-- Indexes for table `private_rooms`
--
ALTER TABLE `private_rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `private_typing`
--
ALTER TABLE `private_typing`
  ADD PRIMARY KEY (`room_id`,`user_id`);

--
-- Indexes for table `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `player_id` (`player_id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `uploaded_images`
--
ALTER TABLE `uploaded_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `auth_token` (`auth_token`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `user_rooms`
--
ALTER TABLE `user_rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_user_code` (`user_id`,`room_code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_deletions`
--
ALTER TABLE `account_deletions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `blocks`
--
ALTER TABLE `blocks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `channel_categories`
--
ALTER TABLE `channel_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=814;

--
-- AUTO_INCREMENT for table `communities`
--
ALTER TABLE `communities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `community_audit`
--
ALTER TABLE `community_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;

--
-- AUTO_INCREMENT for table `community_bans`
--
ALTER TABLE `community_bans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `community_blocks`
--
ALTER TABLE `community_blocks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `community_block_messages`
--
ALTER TABLE `community_block_messages`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `community_block_state`
--
ALTER TABLE `community_block_state`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `community_member_roles`
--
ALTER TABLE `community_member_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `community_roles`
--
ALTER TABLE `community_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `community_timeouts`
--
ALTER TABLE `community_timeouts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `dm_messages`
--
ALTER TABLE `dm_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=270;

--
-- AUTO_INCREMENT for table `dm_typing`
--
ALTER TABLE `dm_typing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=799;

--
-- AUTO_INCREMENT for table `friendships`
--
ALTER TABLE `friendships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT for table `modmail`
--
ALTER TABLE `modmail`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2643649;

--
-- AUTO_INCREMENT for table `private_messages`
--
ALTER TABLE `private_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7086;

--
-- AUTO_INCREMENT for table `private_rooms`
--
ALTER TABLE `private_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `uploaded_images`
--
ALTER TABLE `uploaded_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=132;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `user_rooms`
--
ALTER TABLE `user_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `community_member_roles`
--
ALTER TABLE `community_member_roles`
  ADD CONSTRAINT `community_member_roles_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `community_roles` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
