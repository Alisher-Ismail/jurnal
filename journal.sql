-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 18, 2024 at 02:48 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.0.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `journal`
--

-- --------------------------------------------------------

--
-- Table structure for table `article`
--

CREATE TABLE `article` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `volume` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `keywords` varchar(255) DEFAULT NULL,
  `author` varchar(255) DEFAULT NULL,
  `abstract` text CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `pdf` varchar(200) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `article`
--

INSERT INTO `article` (`id`, `volume`, `title`, `keywords`, `author`, `abstract`, `pdf`, `created_at`, `updated_at`) VALUES
(21, 'Volume 1 Issue 3', 'DENGIZ CHIQINDISINI YIGʻUVCHI ARDUINOGA ASOSLANGAN ROBOTNI MODELLASHTIRISH', 'Arduino, esp8266, mobil ilova, avtomatlashtirish, masofaviy boshqarish, ma’lumotlar tahlili, shaffof suv, suvdagi chiqindilarni yigʻuvchi', 'Ismoilov Alisher Shokirovich, Xasanov Jamshidbek Nasirdin oʻgʻli', 'Annotatsiya: Bugungi kunda dengiz va koʻllar koʻplab chiqindilar inqiroziga\r\nduch kelmoqdalar – jadal iqtisodiy rivojlanish, aholining tez suratlarda oʻsishi,\r\nurbanizatsiyalashmaganlik. Hozirgi kunda chiqindi yigʻishning sinab koʻrilgan\r\nkoʻplab usullari samarasiz ekanligini koʻrmoqdamiz. Hozirgi kunda dunyoda juda\r\nkoʻplab izlanuvchilar dengizdan axlat yigʻishning qulay va samaraliroq boʻlgan\r\nusullarini koʻrib chiqmoqda. Ushbu maqolada Arduino mikrokontrolleridan\r\nfoydalangan holda dengiz va koʻl uchun Garbage Collector roboti taqdim etilgan.\r\nRobot 12V batareya bilan ishlaydi. Robot harakati Arduino dasturlash orqali\r\nboshqariladi. Robot dengizda, koʻllarda axlat yigʻish uchun moʻljallangan. Robot\r\nmasofadan turib mobil telefon orqali boshqarilishi yoki avtomatik ravishda ishlashi\r\nmumkin. Esp8266 Wi-Fi router yordamida robot va telefon oʻrtasida aloqa\r\noʻrnatiladi. Robotni loyqa yuzalarda ishlatib boʻlmaydi. Robot toʻsiqqa duch\r\nkelganda, dasturda qoʻllanilgan shartlarga qarab robot keyingi harakatga oʻtadi va\r\nrobot chiqindini avtomatik ravishda yigʻib oladi.\r\nKalit soʻzlar: Arduino, esp8266, mobil ilova, avtomatlashtirish, masofaviy\r\nboshqarish, ma’lumotlar tahlili, shaffof suv, suvdagi chiqindilarni yigʻuvchi', 'pdfs/D8oGSVsQPLgGsxQd8YBquqjZok3YoZE72xRkQjci.pdf', '2024-07-17 08:10:35', '2024-07-18 05:15:45');

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '2014_10_12_000000_create_users_table', 1),
(2, '2014_10_12_100000_create_password_resets_table', 1),
(3, '2019_08_19_000000_create_failed_jobs_table', 1),
(4, '2019_12_14_000001_create_personal_access_tokens_table', 1),
(5, '2024_07_07_123910_add_zoom_token_to_users_table', 1),
(6, '2024_07_10_130655_create_vaqt_table', 1),
(7, '2024_07_12_081423_create_navbat_table', 1),
(8, '2024_07_16_083858_create_volume_table', 2),
(9, '2024_07_16_110830_create_article_table', 3);

-- --------------------------------------------------------

--
-- Table structure for table `navbat`
--

CREATE TABLE `navbat` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `userid` int(11) NOT NULL,
  `sana` varchar(255) NOT NULL,
  `vaqt` varchar(255) NOT NULL,
  `ism` varchar(255) NOT NULL,
  `tel` varchar(255) NOT NULL,
  `maqsad` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `type` varchar(100) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `muddat` date DEFAULT NULL,
  `firmaid` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `manzil` varchar(400) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `tel` varchar(200) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `mutaxassislik` varchar(200) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `type`, `remember_token`, `muddat`, `firmaid`, `created_at`, `updated_at`, `manzil`, `tel`, `mutaxassislik`) VALUES
(1, 'alisher', 'alisherismailov1991@gmail.com', '$2y$10$LiB6ioyAaQOLjPrLgpjQ4OXyBVxpqsPkWICP3pb/j.S7YEclbLp3q', 'superadmin', NULL, '2024-08-04', 0, '2024-04-19 10:55:12', '2024-07-04 11:10:32', NULL, NULL, NULL),
(28, 'alisher', 'alisher', '$2y$10$lftSN6cocqLcS7RWITqmwuJy2o01q5bS9LUXa5f2RuWL9dVGEcVE2', 'admin', NULL, NULL, 0, '2024-07-10 06:31:13', '2024-07-10 06:31:13', 'andijon', '994997364', NULL),
(29, 'Kamoldin', 'Kamoldin', '$2y$10$qUPtxAGA51RylWWnzDIEd.Ji00cQmUkKKRQYo.25VrVsm12WvTwV2', 'admin', NULL, NULL, 0, '2024-07-11 08:16:26', '2024-07-11 08:16:26', 'andijon', '994997364', 'Kamoldin');

-- --------------------------------------------------------

--
-- Table structure for table `vaqt`
--

CREATE TABLE `vaqt` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `volume`
--

CREATE TABLE `volume` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `volume`
--

INSERT INTO `volume` (`id`, `name`, `isActive`, `created_at`, `updated_at`) VALUES
(16, '1-volume', 0, '2024-07-16 06:45:53', '2024-07-16 06:45:53'),
(17, '3', 0, '2024-07-16 08:23:06', '2024-07-16 08:23:06');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `article`
--
ALTER TABLE `article`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `navbat`
--
ALTER TABLE `navbat`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- Indexes for table `vaqt`
--
ALTER TABLE `vaqt`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `volume`
--
ALTER TABLE `volume`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `article`
--
ALTER TABLE `article`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `navbat`
--
ALTER TABLE `navbat`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `vaqt`
--
ALTER TABLE `vaqt`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `volume`
--
ALTER TABLE `volume`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
