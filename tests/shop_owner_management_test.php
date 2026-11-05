<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/shop_owner_management.php';
function owner_check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function owner_reject(callable $action) {
    try { $action(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected validation rejection');
}
// Shadow production tables for the duration of this connection only.
$pdo->exec('CREATE TEMPORARY TABLE users (user_id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(100), email VARCHAR(120) UNIQUE, phone VARCHAR(30), password_hash VARCHAR(255), role VARCHAR(20)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE outlets (outlet_id INT PRIMARY KEY,state_id INT) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE shop_owner_outlets (user_id INT,outlet_id INT,PRIMARY KEY(user_id,outlet_id)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE password_reset_tokens (user_id INT) ENGINE=InnoDB');
$pdo->exec('INSERT INTO outlets VALUES (1,1),(2,2)');
$pdo->exec("INSERT INTO users (user_id,email,role) VALUES (1,'admin@example.test','admin')");
$input = ['full_name'=>'Test Owner','email'=>'owner@example.test','phone'=>'0123456789',
    'password'=>'OwnerTest!123','confirm_password'=>'OwnerTest!123','outlet_ids'=>['1']];
$id = save_shop_owner($pdo,0,$input);
$_SESSION = ['role'=>'shop_owner','user_id'=>$id];
owner_check(staff_can_access_outlet($pdo,1) && !staff_can_access_outlet($pdo,2), 'Initial scope');
$before = $pdo->query("SELECT password_hash FROM users WHERE user_id=$id")->fetchColumn();
owner_check(password_verify($input['password'],$before), 'Password hashed');
$edit = array_merge($input,['full_name'=>'Updated Owner','email'=>'updated@example.test','phone'=>'0199999999','password'=>'','confirm_password'=>'','outlet_ids'=>['2']]);
save_shop_owner($pdo,$id,$edit);
owner_check(!staff_can_access_outlet($pdo,1) && staff_can_access_outlet($pdo,2), 'Existing session gets revised scope');
owner_check(staff_assigned_state_ids($pdo)===[2], 'State scope follows assigned outlet');
owner_check($pdo->query("SELECT password_hash FROM users WHERE user_id=$id")->fetchColumn()===$before, 'Blank password preserves hash');
owner_reject(fn()=>save_shop_owner($pdo,$id,array_merge($edit,['email'=>'admin@example.test'])));
owner_reject(fn()=>save_shop_owner($pdo,1,$edit));
owner_reject(fn()=>save_shop_owner($pdo,$id,array_merge($edit,['full_name'=>'Bad update','outlet_ids'=>[999]])));
owner_check($pdo->query("SELECT full_name FROM users WHERE user_id=$id")->fetchColumn()==='Updated Owner', 'Invalid assignment rollback');
owner_reject(fn()=>save_shop_owner($pdo,0,array_merge($input,['password'=>'short'])));
$pdo->exec("INSERT INTO password_reset_tokens VALUES ($id)");
save_shop_owner($pdo,$id,array_merge($edit,['password'=>'Replacement!123','confirm_password'=>'Replacement!123','outlet_ids'=>[]]));
owner_check(staff_assigned_outlet_ids($pdo)===[], 'No assignments means no outlet access');
owner_check((int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn()===0, 'Password reset revokes old reset tokens');
owner_check(password_verify('Replacement!123',$pdo->query("SELECT password_hash FROM users WHERE user_id=$id")->fetchColumn()), 'Password changed');
echo "Shop owner creation, updates, validation, rollback, password and scope tests passed. No real accounts changed.\n";
