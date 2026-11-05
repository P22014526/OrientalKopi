USE oriental_kopi_booking;

ALTER TABLE outlets
  ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(30) NULL AFTER location_detail,
  ADD COLUMN IF NOT EXISTS map_url VARCHAR(255) NULL AFTER contact_phone;

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

UPDATE outlets
SET branch_name = 'KLIA 2 Arrival Hall',
    state_id = (SELECT state_id FROM outlet_states WHERE state_name = 'Selangor')
WHERE branch_name = 'KLIA2 Arrival Hall';

UPDATE outlets
SET branch_name = 'KLIA 2 Departure Hall',
    state_id = (SELECT state_id FROM outlet_states WHERE state_name = 'Selangor')
WHERE branch_name = 'KLIA2 Departure Hall';

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

UPDATE outlets o
JOIN outlet_states s ON s.state_id = o.state_id
SET o.is_active = 0
WHERE NOT (
  (s.state_name = 'Kuala Lumpur' AND o.branch_name IN ('Mid Valley Megamall','Pavilion Kuala Lumpur','The Exchange TRX','Suria KLCC','Pavilion Bukit Jalil','Sunway Putra Mall','Pavilion Damansara Heights','Pejabat Pos Besar Lama / BSAS'))
  OR (s.state_name = 'Selangor' AND o.branch_name IN ('KLIA 2 Arrival Hall','KLIA 2 Departure Hall','KLIA 1','KLIA 2 Boarding Hall','Bandar Puteri Puchong','Sunway Pyramid','AEON Cheras Selatan','Sunway Velocity'))
  OR (s.state_name = 'Johor' AND o.branch_name IN ('Paradigm Mall','Senai Airport','Johor Jaya (Flagship)','Mid Valley Southkey','AEON Tebrau City','AEON Bukit Indah'))
  OR (s.state_name = 'Penang' AND o.branch_name IN ('Gurney Plaza','Queensbay Mall','Sunway Carnival'))
  OR (s.state_name = 'Putrajaya' AND o.branch_name IN ('Alamanda Shopping Centre','IOI City Mall'))
  OR (s.state_name = 'Melaka' AND o.branch_name IN ('AEON Bandaraya Melaka'))
  OR (s.state_name = 'Sarawak' AND o.branch_name IN ('Vivacity Mall'))
  OR (s.state_name = 'Sabah' AND o.branch_name IN ('Suria Sabah'))
);

INSERT INTO users (full_name, email, phone, password_hash, role) VALUES
('Sarawak Shop Owner', 'sarawak.owner@orientalkopi.test', '0100000007', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'shop_owner'),
('Sabah Shop Owner', 'sabah.owner@orientalkopi.test', '0100000008', '$2y$12$TiFKh5cxHYvHXmtMsxjumOEDwx2y7/dLIcFYt3DgqS9fdyHpHJfMq', 'shop_owner')
ON DUPLICATE KEY UPDATE email = email;

DELETE soo
FROM shop_owner_outlets soo
JOIN users u ON u.user_id = soo.user_id
WHERE u.email IN (
  'kl.owner@orientalkopi.test',
  'selangor.owner@orientalkopi.test',
  'johor.owner@orientalkopi.test',
  'penang.owner@orientalkopi.test',
  'putrajaya.owner@orientalkopi.test',
  'melaka.owner@orientalkopi.test',
  'sarawak.owner@orientalkopi.test',
  'sabah.owner@orientalkopi.test'
);

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
WHERE u.role = 'shop_owner'
  AND o.is_active = 1;
