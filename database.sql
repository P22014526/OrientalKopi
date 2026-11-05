CREATE DATABASE IF NOT EXISTS oriental_kopi_booking;
USE oriental_kopi_booking;

CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  phone VARCHAR(30),
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','admin','shop_owner') NOT NULL DEFAULT 'customer',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE registration_verifications (
  user_id INT PRIMARY KEY,
  code_hash VARCHAR(255) NULL,
  expires_at DATETIME NULL,
  attempts INT NOT NULL DEFAULT 0,
  last_sent_at DATETIME NULL,
  window_started_at DATETIME NULL,
  send_count INT NOT NULL DEFAULT 0,
  verified_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE outlet_states (
  state_id INT AUTO_INCREMENT PRIMARY KEY,
  state_name VARCHAR(80) NOT NULL UNIQUE,
  display_order INT NOT NULL DEFAULT 0
);

CREATE TABLE outlets (
  outlet_id INT AUTO_INCREMENT PRIMARY KEY,
  state_id INT NOT NULL,
  branch_name VARCHAR(120) NOT NULL,
  location_detail VARCHAR(255) NOT NULL,
  contact_phone VARCHAR(30) NULL,
  map_url VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (state_id) REFERENCES outlet_states(state_id) ON DELETE CASCADE,
  UNIQUE KEY uniq_outlet_branch (state_id, branch_name)
);

CREATE TABLE shop_owner_outlets (
  user_id INT NOT NULL,
  outlet_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, outlet_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (outlet_id) REFERENCES outlets(outlet_id) ON DELETE CASCADE
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
  table_type VARCHAR(20) NULL,
  outlet_id INT NULL,
  status ENUM('Confirmed','Pending','Completed','Cancelled','Declined') NOT NULL DEFAULT 'Confirmed',
  request_type ENUM('Normal','Reschedule','Party') NOT NULL DEFAULT 'Normal',
  old_booking_date DATE NULL,
  old_booking_time TIME NULL,
  event_type VARCHAR(60) NULL,
  special_request TEXT NULL,
  reward_text VARCHAR(120) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (outlet_id) REFERENCES outlets(outlet_id) ON DELETE SET NULL
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

CREATE TABLE reviews (
  review_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  booking_id INT NULL,
  rating INT NOT NULL,
  feedback TEXT NOT NULL,
  admin_reply TEXT NULL,
  replied_at DATETIME NULL,
  replied_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reviews_user_booking (user_id, booking_id),
  KEY fk_reviews_booking (booking_id),
  KEY idx_reviews_replied_by (replied_by),
  FOREIGN KEY (user_id) REFERENCES users(user_id),
  FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE SET NULL,
  FOREIGN KEY (replied_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE settings (
  setting_key VARCHAR(50) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
);

CREATE TABLE staff_activity_log (
  activity_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  outlet_id INT NULL,
  booking_id INT NULL,
  action VARCHAR(80) NOT NULL,
  details VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_staff_activity_user (user_id),
  INDEX idx_staff_activity_outlet (outlet_id),
  INDEX idx_staff_activity_booking (booking_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  FOREIGN KEY (outlet_id) REFERENCES outlets(outlet_id) ON DELETE SET NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE SET NULL
);

INSERT INTO settings (setting_key, setting_value) VALUES
('max_capacity','60'),
('table_inventory','[{"type":"Couple tables","seats":2,"tables":6},{"type":"Standard tables","seats":4,"tables":7},{"type":"Family tables","seats":6,"tables":2},{"type":"Party table","seats":8,"tables":1}]'),
('opening_hours','9:30 AM - 10:30 PM'),
('cancellation_cutoff_minutes','720'),
('no_show_grace_minutes','15'),
('daily_booking_limit','3'),
('location','Queensbay Mall, Penang')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO outlet_states (state_name, display_order) VALUES
('Johor', 1),
('Kedah', 2),
('Kelantan', 3),
('Melaka', 4),
('Negeri Sembilan', 5),
('Pahang', 6),
('Penang', 7),
('Perak', 8),
('Perlis', 9),
('Sabah', 10),
('Sarawak', 11),
('Selangor', 12),
('Terengganu', 13),
('Kuala Lumpur', 14),
('Labuan', 15),
('Putrajaya', 16)
ON DUPLICATE KEY UPDATE display_order = VALUES(display_order);

INSERT INTO outlets (state_id, branch_name, location_detail, contact_phone, map_url) VALUES
((SELECT state_id FROM outlet_states WHERE state_name='Kuala Lumpur'), 'Mid Valley Megamall', 'LG-043, Lower Ground Floor', '018-787 6363', 'https://maps.app.goo.gl/RtTEgytvAQekyoyq5'),
((SELECT state_id FROM outlet_states WHERE state_name='Kuala Lumpur'), 'Pavilion Kuala Lumpur', 'Level 1', '011-1102 6363', 'https://maps.app.goo.gl/mhVotcLNb15T3F5z8'),
((SELECT state_id FROM outlet_states WHERE state_name='Kuala Lumpur'), 'The Exchange TRX', 'Concourse Level', '013-768 6388', 'https://maps.app.goo.gl/w5gWajULq4a3Y8u86'),
((SELECT state_id FROM outlet_states WHERE state_name='Kuala Lumpur'), 'Suria KLCC', 'Level 4', '011-1107 6363', 'https://maps.app.goo.gl/H5V7B9nyXu6FyKZZ9'),
((SELECT state_id FROM outlet_states WHERE state_name='Kuala Lumpur'), 'Pavilion Bukit Jalil', 'Level 1', '018-782 6363', 'https://maps.app.goo.gl/bBFmf9uXQri1tRr68'),
((SELECT state_id FROM outlet_states WHERE state_name='Kuala Lumpur'), 'Sunway Putra Mall', 'Ground Floor', '018-959 6363', 'https://maps.app.goo.gl/BGrRknhDnYLJemcu8'),
((SELECT state_id FROM outlet_states WHERE state_name='Kuala Lumpur'), 'Pavilion Damansara Heights', 'Level 5', '018-770 6363', 'https://maps.app.goo.gl/GeP2VDhRWmR8BuPx5'),
((SELECT state_id FROM outlet_states WHERE state_name='Kuala Lumpur'), 'Pejabat Pos Besar Lama / BSAS', 'Dataran Merdeka', '018-870 6363', 'https://maps.app.goo.gl/NvoKr55krXLZbkgi7'),
((SELECT state_id FROM outlet_states WHERE state_name='Selangor'), 'KLIA 2 Arrival Hall', 'Arrival Hall', '011-1112 6363', 'https://maps.app.goo.gl/tFzi87NtBE7qXboU7'),
((SELECT state_id FROM outlet_states WHERE state_name='Selangor'), 'KLIA 2 Departure Hall', 'Departure Hall', '011-1108 6363', 'https://maps.app.goo.gl/evv8LcsHMgKNuLHY6'),
((SELECT state_id FROM outlet_states WHERE state_name='Selangor'), 'KLIA 1', 'Airport Terminal', '018-767 6363', 'https://maps.app.goo.gl/sS7pX5a6ff7JbKcH7'),
((SELECT state_id FROM outlet_states WHERE state_name='Selangor'), 'KLIA 2 Boarding Hall', 'Boarding Hall', '018-987 6363', 'https://maps.app.goo.gl/EepNAAU3o9FMWpMc9'),
((SELECT state_id FROM outlet_states WHERE state_name='Selangor'), 'Bandar Puteri Puchong', 'Jalan Puteri 2/3', '011-1199 6363', 'https://maps.app.goo.gl/gWtjLdNr15GxG62o7'),
((SELECT state_id FROM outlet_states WHERE state_name='Selangor'), 'Sunway Pyramid', 'Blue Atrium, Ground Floor', '018-212 6363', 'https://maps.app.goo.gl/Q4ErHE8yTMGLCoyj7'),
((SELECT state_id FROM outlet_states WHERE state_name='Selangor'), 'AEON Cheras Selatan', 'Ground Floor', '018-790 6363', 'https://maps.app.goo.gl/huBAbbnMLLKRkg766'),
((SELECT state_id FROM outlet_states WHERE state_name='Selangor'), 'Sunway Velocity', 'Ground Floor', '018-223 6363', 'https://maps.app.goo.gl/miATnfJCAb9V1yx36'),
((SELECT state_id FROM outlet_states WHERE state_name='Johor'), 'Paradigm Mall', 'Mall Outlet', '018-968 6363', 'https://maps.app.goo.gl/1haYmuErUzTESJce7'),
((SELECT state_id FROM outlet_states WHERE state_name='Johor'), 'Senai Airport', 'Airport Outlet', '018-280 6363', 'https://maps.app.goo.gl/GXyWSgnCNx7swu5C9'),
((SELECT state_id FROM outlet_states WHERE state_name='Johor'), 'Johor Jaya (Flagship)', 'Jalan Dedap 22', '011-2888 6363', 'https://maps.app.goo.gl/3jr6Dxo3rfGKaPzE7'),
((SELECT state_id FROM outlet_states WHERE state_name='Johor'), 'Mid Valley Southkey', 'LG-061', '018-779 6363', 'https://maps.app.goo.gl/sJh6Ut5KTZric1yX8'),
((SELECT state_id FROM outlet_states WHERE state_name='Johor'), 'AEON Tebrau City', 'Ground Floor', '011-1101 6363', 'https://maps.app.goo.gl/YZgfiQwgcSoW5gQe6'),
((SELECT state_id FROM outlet_states WHERE state_name='Johor'), 'AEON Bukit Indah', 'Ground Floor', '018-380 6363', 'https://maps.app.goo.gl/FwzRC3Eh4NZ5BaS96'),
((SELECT state_id FROM outlet_states WHERE state_name='Penang'), 'Gurney Plaza', 'Lower Ground', '018-660 6363', 'https://maps.app.goo.gl/SyGbU8MfhpmGt3WA9'),
((SELECT state_id FROM outlet_states WHERE state_name='Penang'), 'Queensbay Mall', 'Lower Ground', '018-918 6363', 'https://maps.app.goo.gl/nskbx31xTcR24eF99'),
((SELECT state_id FROM outlet_states WHERE state_name='Penang'), 'Sunway Carnival', 'Mall Outlet', '018-910 6363', 'https://maps.app.goo.gl/ewWsQ27JHYC1bHvy5'),
((SELECT state_id FROM outlet_states WHERE state_name='Putrajaya'), 'Alamanda Shopping Centre', 'Lower Ground', '011-1103 6363', 'https://maps.app.goo.gl/LrxFSLC5SeuPqwAj9'),
((SELECT state_id FROM outlet_states WHERE state_name='Putrajaya'), 'IOI City Mall', 'Ground Floor', '018-282 6363', 'https://maps.app.goo.gl/8zBQKPffGA1z92gG6'),
((SELECT state_id FROM outlet_states WHERE state_name='Melaka'), 'AEON Bandaraya Melaka', 'Ground Floor', '018-270 6363', 'https://maps.app.goo.gl/3dJ1vxPyAcyayXNt8'),
((SELECT state_id FROM outlet_states WHERE state_name='Sarawak'), 'Vivacity Mall', 'Mall Outlet', '018-980 6363', 'https://maps.app.goo.gl/Z9m7UtBmuFfm6rsBA'),
((SELECT state_id FROM outlet_states WHERE state_name='Sabah'), 'Suria Sabah', 'Mall Outlet', '018-961 6363', 'https://maps.app.goo.gl/A4uuHMp4epaHjJ588')
ON DUPLICATE KEY UPDATE
  location_detail = VALUES(location_detail),
  contact_phone = VALUES(contact_phone),
  map_url = VALUES(map_url),
  is_active = 1;

-- Default password for both accounts: password123
INSERT INTO users (full_name, email, phone, password_hash, role) VALUES
('Admin User', 'admin@orientalkopi.test', '0123456789', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'admin'),
('John Doe', 'customer@orientalkopi.test', '0123333333', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'customer'),
('Kuala Lumpur Shop Owner', 'kl.owner@orientalkopi.test', '0100000001', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'shop_owner'),
('Selangor Shop Owner', 'selangor.owner@orientalkopi.test', '0100000002', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'shop_owner'),
('Johor Shop Owner', 'johor.owner@orientalkopi.test', '0100000003', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'shop_owner'),
('Penang Shop Owner', 'penang.owner@orientalkopi.test', '0100000004', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'shop_owner'),
('Putrajaya Shop Owner', 'putrajaya.owner@orientalkopi.test', '0100000005', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'shop_owner'),
('Melaka Shop Owner', 'melaka.owner@orientalkopi.test', '0100000006', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'shop_owner'),
('Sarawak Shop Owner', 'sarawak.owner@orientalkopi.test', '0100000007', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'shop_owner'),
('Sabah Shop Owner', 'sabah.owner@orientalkopi.test', '0100000008', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'shop_owner')
ON DUPLICATE KEY UPDATE email=email;

INSERT IGNORE INTO shop_owner_outlets (user_id, outlet_id)
SELECT u.user_id, o.outlet_id
FROM users u
JOIN outlet_states s
  ON (
    (u.email = 'kl.owner@orientalkopi.test' AND s.state_name = 'Kuala Lumpur')
    OR (u.email = 'selangor.owner@orientalkopi.test' AND s.state_name = 'Selangor')
    OR (u.email = 'johor.owner@orientalkopi.test' AND s.state_name = 'Johor')
    OR (u.email = 'penang.owner@orientalkopi.test' AND s.state_name = 'Penang')
    OR (u.email = 'putrajaya.owner@orientalkopi.test' AND s.state_name = 'Putrajaya')
    OR (u.email = 'melaka.owner@orientalkopi.test' AND s.state_name = 'Melaka')
    OR (u.email = 'sarawak.owner@orientalkopi.test' AND s.state_name = 'Sarawak')
    OR (u.email = 'sabah.owner@orientalkopi.test' AND s.state_name = 'Sabah')
  )
JOIN outlets o ON o.state_id = s.state_id
WHERE u.role = 'shop_owner';

INSERT INTO faqs (question, answer, keywords, created_by) VALUES
('What are the opening hours?', 'Oriental Kopi Queensbay Mall is open from 9:30 AM to 10:30 PM.', 'opening,hours,time,open,close', 1),
('Where is the restaurant located?', 'The restaurant is located at Queensbay Mall, Penang.', 'location,where,address,queensbay', 1),
('Can I cancel my booking?', 'Yes. Customers can cancel a confirmed booking from the My Bookings page.', 'cancel,cancellation,booking', 1),
('Can I reschedule my booking?', 'Yes. Customers can send a reschedule request. The admin will approve or decline it based on availability.', 'reschedule,change,time,date', 1),
('Do you accept party bookings?', 'Yes. If the booking is more than 6 pax, the system will offer party reservation assistance.', 'party,birthday,group,event,pax', 1)
ON DUPLICATE KEY UPDATE question=question;
