CREATE DATABASE IF NOT EXISTS oriental_kopi_booking;
USE oriental_kopi_booking;

CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  phone VARCHAR(30),
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE password_reset_tokens (
  reset_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  requested_ip VARCHAR(45) NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_password_reset_user (user_id),
  INDEX idx_password_reset_expires (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE bookings (
  booking_id INT AUTO_INCREMENT PRIMARY KEY,
  booking_code VARCHAR(20) UNIQUE,
  user_id INT NOT NULL,
  customer_name VARCHAR(100) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  booking_date DATE NOT NULL,
  booking_time TIME NOT NULL,
  pax INT NOT NULL,
  status ENUM('Confirmed','Pending','Completed','Cancelled','Declined') NOT NULL DEFAULT 'Confirmed',
  request_type ENUM('Normal','Reschedule','Party') NOT NULL DEFAULT 'Normal',
  old_booking_date DATE NULL,
  old_booking_time TIME NULL,
  event_type VARCHAR(60) NULL,
  special_request TEXT NULL,
  reward_text VARCHAR(120) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE faqs (
  faq_id INT AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(255) NOT NULL,
  answer TEXT NOT NULL,
  keywords VARCHAR(255) NOT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE settings (
  setting_key VARCHAR(50) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
);

INSERT INTO settings (setting_key, setting_value) VALUES
('max_capacity','60'),
('opening_hours','9:30 AM - 10:30 PM'),
('location','Queensbay Mall, Penang')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Default password for both accounts: password123
INSERT INTO users (full_name, email, phone, password_hash, role) VALUES
('Admin User', 'admin@orientalkopi.test', '0123456789', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'admin'),
('John Doe', 'customer@orientalkopi.test', '0123333333', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'customer')
ON DUPLICATE KEY UPDATE email=email;

INSERT INTO faqs (question, answer, keywords, created_by) VALUES
('What are the opening hours?', 'Oriental Kopi Queensbay Mall is open from 9:30 AM to 10:30 PM.', 'opening,hours,time,open,close', 1),
('Where is the restaurant located?', 'The restaurant is located at Queensbay Mall, Penang.', 'location,where,address,queensbay', 1),
('Can I cancel my booking?', 'Yes. Customers can cancel a confirmed booking from the My Bookings page.', 'cancel,cancellation,booking', 1),
('Can I reschedule my booking?', 'Yes. Customers can send a reschedule request. The admin will approve or decline it based on availability.', 'reschedule,change,time,date', 1),
('Do you accept party bookings?', 'Yes. If the booking is more than 6 pax, the system will offer party reservation assistance.', 'party,birthday,group,event,pax', 1)
ON DUPLICATE KEY UPDATE question=question;
