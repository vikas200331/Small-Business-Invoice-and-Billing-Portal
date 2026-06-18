-- Invoice Portal Database Schema
CREATE DATABASE IF NOT EXISTS `invoice_portal` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `invoice_portal`;

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Clients Table
CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `company` VARCHAR(255),
  `phone` VARCHAR(50),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Invoices Table
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `client_id` INT NOT NULL,
  `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
  `issue_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Invoice Items Table
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Payments Table
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_date` DATE NOT NULL,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
