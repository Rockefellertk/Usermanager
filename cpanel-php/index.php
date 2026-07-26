<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$route = (string) ($_GET['r'] ?? 'dashboard');

if ($route === 'login') {
    if (Auth::user()) {
        redirect_to('dashboard');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (Auth::attempt((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            flash('success', tr('خوش آمدید.', 'Welcome back.'));
            redirect_to('dashboard');
        }
        flash('error', tr('نام کاربری یا رمز نادرست است؛ پس از ۵ تلاش، ورود ۵ دقیقه محدود می‌شود.', 'Invalid credentials. Login is limited for five minutes after five failures.'));
    }
    render('login', ['pageTitle' => tr('ورود', 'Sign in')]);
    exit;
}

if ($route === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        Auth::logout();
    }
    redirect_to('login');
}

if ($route === 'language') {
    require_login();
    csrf_check();
    $language = ($_POST['language'] ?? 'fa') === 'en' ? 'en' : 'fa';
    $_SESSION['language'] = $language;
    Database::execute('UPDATE admins SET language = ? WHERE id = ?', [$language, Auth::user()['id']]);
    redirect_to((string) ($_POST['back'] ?? 'dashboard'));
}

require_login();

switch ($route) {
    case 'live-sync':
        require_write(); csrf_check();
        header('Content-Type: application/json; charset=utf-8');
        $result = [];
        foreach (Database::fetchAll('SELECT id,last_sync_at FROM routers WHERE is_active=1') as $liveRouter) {
            try {
                $item = ['poll' => poll_router((int) $liveRouter['id'])];
                $lastSync = $liveRouter['last_sync_at'] ? strtotime((string) $liveRouter['last_sync_at']) : 0;
                if ($lastSync < time() - 60) {
                    $item['sync'] = sync_router((int) $liveRouter['id']);
                }
                $result[(int) $liveRouter['id']] = $item;
            } catch (Throwable $exception) {
                $result[(int) $liveRouter['id']] = ['error' => $exception->getMessage()];
            }
        }
        echo json_encode(['ok' => true, 'routers' => $result], JSON_UNESCAPED_UNICODE);
        exit;

    case 'dashboard':
        overdue_sweep();
        $stats = [
            'total_users' => (int) (Database::fetch('SELECT COUNT(*) AS c FROM ppp_users')['c'] ?? 0),
            'active_users' => (int) (Database::fetch('SELECT COUNT(*) AS c FROM ppp_users WHERE status = "active"')['c'] ?? 0),
            'expired_users' => (int) (Database::fetch('SELECT COUNT(*) AS c FROM ppp_users WHERE status = "expired"')['c'] ?? 0),
            'online_users' => (int) (Database::fetch('SELECT COUNT(DISTINCT CONCAT(router_id, ":", username)) AS c FROM active_sessions WHERE last_seen_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)')['c'] ?? 0),
            'traffic_in' => (int) (Database::fetch('SELECT COALESCE(SUM(bytes_in),0) AS c FROM traffic_logs WHERE log_date = CURDATE()')['c'] ?? 0),
            'traffic_out' => (int) (Database::fetch('SELECT COALESCE(SUM(bytes_out),0) AS c FROM traffic_logs WHERE log_date = CURDATE()')['c'] ?? 0),
            'revenue' => (float) (Database::fetch('SELECT COALESCE(SUM(amount),0) AS c FROM payments WHERE received_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")')['c'] ?? 0),
            'unpaid' => (float) (Database::fetch('SELECT COALESCE(SUM(i.total - COALESCE(p.paid,0)),0) AS c FROM invoices i LEFT JOIN (SELECT invoice_id,SUM(amount) paid FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.status IN ("unpaid","overdue")')['c'] ?? 0),
            'overdue' => (int) (Database::fetch('SELECT COUNT(*) AS c FROM invoices WHERE status = "overdue"')['c'] ?? 0),
        ];
        $routers = Database::fetchAll('SELECT * FROM routers WHERE is_active = 1 ORDER BY name');
        $recent = Database::fetchAll('SELECT l.*, a.username AS admin_name FROM activity_logs l LEFT JOIN admins a ON a.id=l.admin_id ORDER BY l.id DESC LIMIT 8');
        render('dashboard', compact('stats', 'routers', 'recent') + ['pageTitle' => tr('داشبورد', 'Dashboard')]);
        break;

    case 'routers':
        $routers = Database::fetchAll('SELECT r.*, COUNT(u.id) AS user_count FROM routers r LEFT JOIN ppp_users u ON u.router_id=r.id GROUP BY r.id ORDER BY r.name');
        render('routers', compact('routers') + ['pageTitle' => tr('روترها', 'Routers')]);
        break;

    case 'router-form':
        require_superadmin();
        $id = max(0, (int) ($_GET['id'] ?? 0));
        $router = $id ? Database::fetch('SELECT * FROM routers WHERE id = ?', [$id]) : null;
        if ($id && !$router) {
            flash('error', tr('روتر پیدا نشد.', 'Router not found.'));
            redirect_to('routers');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            try {
                $name = trim((string) ($_POST['name'] ?? ''));
                $host = trim((string) ($_POST['host'] ?? ''));
                $port = (int) ($_POST['port'] ?? 443);
                $username = trim((string) ($_POST['username'] ?? ''));
                if ($name === '' || $host === '' || $username === '' || $port < 1 || $port > 65535 || str_contains($host, '/') || str_contains($host, '://')) {
                    throw new RuntimeException(tr('اطلاعات روتر کامل یا معتبر نیست.', 'Router details are incomplete or invalid.'));
                }
                $useApiKey = isset($_POST['use_api_key']) ? 1 : 0;
                $password = (string) ($_POST['password'] ?? '');
                $apiKey = (string) ($_POST['api_key'] ?? '');
                if (!$id && (($useApiKey && $apiKey === '') || (!$useApiKey && $password === ''))) {
                    throw new RuntimeException(tr('رمز یا API Key را وارد کنید.', 'Enter a password or API key.'));
                }
                if ($id) {
                    $passwordEncrypted = $password !== '' ? encrypt_secret($password) : $router['password_encrypted'];
                    $apiKeyEncrypted = $apiKey !== '' ? encrypt_secret($apiKey) : $router['api_key_encrypted'];
                    Database::execute('UPDATE routers SET name=?, host=?, port=?, username=?, password_encrypted=?, use_api_key=?, api_key_encrypted=?, use_tls=?, verify_tls=?, is_active=? WHERE id=?', [
                        $name, $host, $port, $username, $passwordEncrypted, $useApiKey, $apiKeyEncrypted,
                        isset($_POST['use_tls']) ? 1 : 0, isset($_POST['verify_tls']) ? 1 : 0, isset($_POST['is_active']) ? 1 : 0, $id,
                    ]);
                    log_activity('router_update', 'router', $id, ['name' => $name]);
                } else {
                    Database::execute('INSERT INTO routers (name,host,port,username,password_encrypted,use_api_key,api_key_encrypted,use_tls,verify_tls,is_active,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())', [
                        $name, $host, $port, $username, encrypt_secret($password), $useApiKey, encrypt_secret($apiKey),
                        isset($_POST['use_tls']) ? 1 : 0, isset($_POST['verify_tls']) ? 1 : 0, isset($_POST['is_active']) ? 1 : 0,
                    ]);
                    $id = Database::id();
                    log_activity('router_create', 'router', $id, ['name' => $name]);
                }
                flash('success', tr('روتر ذخیره شد.', 'Router saved.'));
                redirect_to('routers');
            } catch (Throwable $exception) {
                flash('error', $exception->getMessage());
                $router = array_merge($router ?? [], $_POST);
            }
        }
        render('router_form', compact('router', 'id') + ['pageTitle' => $id ? tr('ویرایش روتر', 'Edit router') : tr('افزودن روتر', 'Add router')]);
        break;

    case 'router-test':
        require_superadmin();
        csrf_check();
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $router = router_by_id($id);
            $online = router_client($router)->ping();
            Database::execute('UPDATE routers SET last_status = ? WHERE id = ?', [$online ? 'online' : 'offline', $id]);
            $sync = sync_router($id);
            $poll = poll_router($id);
            flash('success', tr('اتصال برقرار و اطلاعات روتر همگام شد. کاربران جدید: ', 'Connected and synchronized. New users: ') . ($sync['created'] ?? 0) . tr('، نشست‌های آنلاین: ', ', online sessions: ') . ($poll['sessions'] ?? 0));
        } catch (Throwable $exception) {
            flash('error', $exception->getMessage());
        }
        redirect_to('routers');

    case 'router-sync':
        require_write();
        csrf_check();
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $result = sync_router($id);
            $poll = poll_router($id);
            $message = tr('همگام‌سازی انجام شد.', 'Synchronization completed.') . ' ' . json_encode($result + $poll, JSON_UNESCAPED_UNICODE);
            flash(!empty($result['paused']) ? 'warning' : 'success', $message);
        } catch (Throwable $exception) {
            flash('error', $exception->getMessage());
        }
        redirect_to('routers');

    case 'router-data':
        $id = max(0, (int) ($_GET['id'] ?? 0));
        try {
            $router = router_by_id($id);
            $snapshot = router_client($router)->userManagerSnapshot();
            render('router_data', compact('router', 'snapshot') + ['pageTitle' => tr('اطلاعات User Manager', 'User Manager data')]);
        } catch (Throwable $exception) {
            flash('error', $exception->getMessage());
            redirect_to('routers');
        }
        break;

    case 'router-delete':
        require_superadmin();
        csrf_check();
        $id = (int) ($_POST['id'] ?? 0);
        $count = (int) (Database::fetch('SELECT COUNT(*) AS c FROM ppp_users WHERE router_id = ?', [$id])['c'] ?? 0);
        if ($count > 0) {
            flash('error', tr('این روتر کاربر دارد و قابل حذف نیست.', 'This router has users and cannot be deleted.'));
        } else {
            Database::execute('DELETE FROM routers WHERE id = ?', [$id]);
            log_activity('router_delete', 'router', $id);
            flash('success', tr('روتر حذف شد.', 'Router deleted.'));
        }
        redirect_to('routers');

    case 'plans':
        $plans = Database::fetchAll('SELECT p.*,r.name AS router_name,COUNT(u.id) AS user_count FROM plans p LEFT JOIN routers r ON r.id=p.router_id LEFT JOIN ppp_users u ON u.plan_id=p.id GROUP BY p.id ORDER BY r.name,p.name');
        render('plans', compact('plans') + ['pageTitle' => tr('پلن‌ها', 'Plans')]);
        break;

    case 'plan-form':
        require_write();
        $id = max(0, (int) ($_GET['id'] ?? 0));
        $plan = $id ? Database::fetch('SELECT * FROM plans WHERE id = ?', [$id]) : null;
        if ($id && !$plan) {
            redirect_to('plans');
        }
        $routers = Database::fetchAll('SELECT id,name FROM routers WHERE is_active=1 ORDER BY name');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            try {
                $values = [
                    trim((string) ($_POST['name'] ?? '')), trim((string) ($_POST['mikrotik_profile'] ?? '')),
                    trim((string) ($_POST['rate_limit'] ?? '')), (float) ($_POST['price'] ?? 0),
                    strtoupper(trim((string) ($_POST['currency'] ?? 'IRR'))), (int) ($_POST['validity_days'] ?? 30),
                    ($_POST['data_cap_gb'] ?? '') === '' ? null : (int) $_POST['data_cap_gb'], isset($_POST['is_active']) ? 1 : 0,
                ];
                $planRouterId = max(0, (int) ($_POST['router_id'] ?? 0));
                if ($planRouterId < 1 || $values[0] === '' || $values[1] === '' || $values[2] === '' || $values[3] < 0 || $values[5] < 1) {
                    throw new RuntimeException(tr('اطلاعات پلن معتبر نیست.', 'Plan details are invalid.'));
                }
                $syncedRouters = sync_plan_to_router($planRouterId, $values[1], $values[2], $values[5], $values[6], $values[3]);
                if ($id) {
                    Database::execute('UPDATE plans SET router_id=?,name=?, mikrotik_profile=?, rate_limit=?, price=?, currency=?, validity_days=?, data_cap_gb=?, is_active=? WHERE id=?', [$planRouterId,...$values, $id]);
                    log_activity('plan_update', 'plan', $id, ['name' => $values[0]]);
                } else {
                    Database::execute('INSERT INTO plans (router_id,name,mikrotik_profile,rate_limit,price,currency,validity_days,data_cap_gb,is_active,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())', [$planRouterId,...$values]);
                    $id = Database::id();
                    log_activity('plan_create', 'plan', $id, ['name' => $values[0]]);
                }
                log_activity('plan_router_sync', 'plan', $id, ['routers' => $syncedRouters, 'profile' => $values[1]]);
                flash('success', tr('پلن ذخیره و پروفایل روی روتر اعمال شد.', 'Plan saved and router profile applied.'));
                redirect_to('plans');
            } catch (Throwable $exception) {
                flash('error', $exception->getMessage());
                $plan = array_merge($plan ?? [], $_POST);
            }
        }
        render('plan_form', compact('plan', 'id', 'routers') + ['pageTitle' => $id ? tr('ویرایش پلن', 'Edit plan') : tr('افزودن پلن', 'Add plan')]);
        break;

    case 'plan-delete':
        require_write();
        csrf_check();
        $id = (int) ($_POST['id'] ?? 0);
        Database::execute('DELETE FROM plans WHERE id = ?', [$id]);
        log_activity('plan_delete', 'plan', $id);
        flash('success', tr('پلن حذف شد؛ کاربران قبلی بدون پلن باقی ماندند.', 'Plan deleted; existing users now have no plan.'));
        redirect_to('plans');

    case 'users':
        $search = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $routerId = max(0, (int) ($_GET['router'] ?? 0));
        $where = ['1=1']; $params = [];
        if ($search !== '') { $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ?)'; $like = '%' . $search . '%'; array_push($params, $like, $like, $like); }
        if ($status === 'online') {
            $where[] = 'EXISTS(SELECT 1 FROM active_sessions sx WHERE sx.router_id=u.router_id AND sx.username=u.username AND sx.last_seen_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE))';
        } elseif ($status !== '') { $where[] = 'u.status = ?'; $params[] = $status; }
        if ($routerId) { $where[] = 'u.router_id = ?'; $params[] = $routerId; }
        $whereSql = implode(' AND ', $where);
        $page = page_number(); $perPage = 25; $offset = ($page - 1) * $perPage;
        $total = (int) (Database::fetch('SELECT COUNT(*) AS c FROM ppp_users u WHERE ' . $whereSql, $params)['c'] ?? 0);
        $users = Database::fetchAll(
            'SELECT u.*, p.name AS plan_name, p.currency, r.name AS router_name, EXISTS(SELECT 1 FROM active_sessions s WHERE s.router_id=u.router_id AND s.username=u.username AND s.last_seen_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS is_online FROM ppp_users u LEFT JOIN plans p ON p.id=u.plan_id JOIN routers r ON r.id=u.router_id WHERE ' . $whereSql . ' ORDER BY u.username LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        $routers = Database::fetchAll('SELECT id,name FROM routers ORDER BY name');
        render('users', compact('users', 'routers', 'search', 'status', 'routerId', 'page', 'perPage', 'total') + ['pageTitle' => tr('کاربران User Manager', 'User Manager users')]);
        break;

    case 'user-form':
        require_write();
        $id = max(0, (int) ($_GET['id'] ?? 0));
        $user = $id ? Database::fetch('SELECT * FROM ppp_users WHERE id = ?', [$id]) : null;
        if ($id && !$user) {
            redirect_to('users');
        }
        $routers = Database::fetchAll('SELECT id,name FROM routers WHERE is_active=1 ORDER BY name');
        $plans = Database::fetchAll('SELECT id,router_id,name,rate_limit,price,currency,validity_days FROM plans WHERE is_active=1 AND router_id IS NOT NULL ORDER BY name');
        if ($id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            try {
                foreach (router_client(router_by_id((int) $user['router_id']))->listSecrets((string) $user['username']) as $remoteUser) {
                    if (($remoteUser['name'] ?? '') === $user['username']) {
                        $user['shared_users'] = max(1, (int) ($remoteUser['shared-users'] ?? 1));
                        break;
                    }
                }
            } catch (Throwable) {
                $user['shared_users'] = 1;
            }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            try {
                $input = [
                    'router_id' => (int) ($_POST['router_id'] ?? 0), 'plan_id' => (int) ($_POST['plan_id'] ?? 0),
                    'username' => trim((string) ($_POST['username'] ?? '')), 'password' => (string) ($_POST['password'] ?? ''),
                    'service' => (string) ($_POST['service'] ?? 'pppoe'), 'rate_limit' => trim((string) ($_POST['rate_limit'] ?? '')),
                    'full_name' => trim((string) ($_POST['full_name'] ?? '')), 'phone' => trim((string) ($_POST['phone'] ?? '')),
                    'address' => trim((string) ($_POST['address'] ?? '')), 'comment' => trim((string) ($_POST['comment'] ?? '')),
                    'shared_users' => max(1, min(100, (int) ($_POST['shared_users'] ?? 1))),
                ];
                if ((!$id && (!clean_username($input['username']) || $input['password'] === '')) || $input['plan_id'] < 1 || !in_array($input['service'], ['pppoe','pptp','l2tp','sstp','any'], true)) {
                    throw new RuntimeException(tr('اطلاعات کاربر کامل یا معتبر نیست.', 'User details are incomplete or invalid.'));
                }
                if ($id) {
                    update_ppp_user($id, $input);
                    flash('success', tr('کاربر ویرایش شد.', 'User updated.'));
                } else {
                    $created = create_ppp_user($input);
                    $id = $created['user_id'];
                    flash('success', tr('کاربر ساخته شد و فاکتور ایجاد گردید: ', 'User created and invoice generated: ') . $created['invoice']['invoice_number']);
                }
                redirect_to('user-detail', ['id' => $id]);
            } catch (Throwable $exception) {
                flash('error', $exception->getMessage());
                $user = array_merge($user ?? [], $_POST);
            }
        }
        render('user_form', compact('user', 'routers', 'plans', 'id') + ['pageTitle' => $id ? tr('ویرایش کاربر', 'Edit user') : tr('افزودن کاربر', 'Add user')]);
        break;

    case 'user-detail':
        $id = (int) ($_GET['id'] ?? 0);
        $user = Database::fetch('SELECT u.*,p.name AS plan_name,p.price,p.currency,p.validity_days,r.name AS router_name FROM ppp_users u LEFT JOIN plans p ON p.id=u.plan_id JOIN routers r ON r.id=u.router_id WHERE u.id=?', [$id]);
        if (!$user) { redirect_to('users'); }
        $session = Database::fetch('SELECT * FROM active_sessions WHERE router_id=? AND username=? AND last_seen_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY last_seen_at DESC LIMIT 1', [$user['router_id'], $user['username']]);
        $traffic = Database::fetchAll('SELECT * FROM traffic_logs WHERE local_user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ORDER BY log_date', [$id]);
        $invoices = Database::fetchAll('SELECT i.*, COALESCE(SUM(p.amount),0) AS paid_amount FROM invoices i LEFT JOIN payments p ON p.invoice_id=i.id WHERE i.local_user_id=? GROUP BY i.id ORDER BY i.id DESC LIMIT 20', [$id]);
        render('user_detail', compact('user', 'session', 'traffic', 'invoices') + ['pageTitle' => $user['username']]);
        break;

    case 'user-toggle':
        require_write(); csrf_check(); $id = (int) ($_POST['id'] ?? 0);
        try { $status = toggle_ppp_user($id); flash('success', status_text($status)); } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect_to('user-detail', ['id' => $id]);

    case 'user-renew':
        require_write(); csrf_check(); $id = (int) ($_POST['id'] ?? 0);
        try { $invoice = renew_ppp_user($id); flash('success', tr('تمدید انجام شد. فاکتور: ', 'Renewed. Invoice: ') . $invoice['invoice_number']); } catch (Throwable $e) { flash('error', $e->getMessage()); }
        redirect_to('user-detail', ['id' => $id]);

    case 'user-delete':
        require_write(); csrf_check(); $id = (int) ($_POST['id'] ?? 0);
        try { delete_ppp_user($id); flash('success', tr('کاربر از روتر و پنل حذف شد.', 'User deleted from router and panel.')); redirect_to('users'); } catch (Throwable $e) { flash('error', $e->getMessage()); redirect_to('user-detail', ['id'=>$id]); }

    case 'invoices':
        $search = trim((string) ($_GET['q'] ?? '')); $status = trim((string) ($_GET['status'] ?? ''));
        $where = ['1=1']; $params=[];
        if ($search !== '') { $where[]='(i.invoice_number LIKE ? OR u.username LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like); }
        if ($status !== '') { $where[]='i.status=?'; $params[]=$status; }
        $whereSql=implode(' AND ',$where); $page=page_number(); $perPage=25; $offset=($page-1)*$perPage;
        $total=(int)(Database::fetch('SELECT COUNT(*) c FROM invoices i JOIN ppp_users u ON u.id=i.local_user_id WHERE '.$whereSql,$params)['c']??0);
        $invoices=Database::fetchAll('SELECT i.*,u.username,p.name plan_name,p.currency,COALESCE(SUM(pay.amount),0) paid_amount FROM invoices i JOIN ppp_users u ON u.id=i.local_user_id LEFT JOIN plans p ON p.id=i.plan_id LEFT JOIN payments pay ON pay.invoice_id=i.id WHERE '.$whereSql.' GROUP BY i.id ORDER BY i.id DESC LIMIT '.$perPage.' OFFSET '.$offset,$params);
        $grandTotal=(float)(Database::fetch('SELECT COALESCE(SUM(total),0) c FROM invoices WHERE status<>"cancelled"')['c']??0);
        render('invoices',compact('invoices','search','status','page','perPage','total','grandTotal')+['pageTitle'=>tr('صورتحساب‌ها','Invoices')]);
        break;

    case 'invoice-detail':
        $id=(int)($_GET['id']??0);
        $invoice=Database::fetch('SELECT i.*,u.username,u.full_name,p.name plan_name,p.currency,a.username created_by_name,COALESCE(SUM(pay.amount),0) paid_amount FROM invoices i JOIN ppp_users u ON u.id=i.local_user_id LEFT JOIN plans p ON p.id=i.plan_id LEFT JOIN admins a ON a.id=i.created_by LEFT JOIN payments pay ON pay.invoice_id=i.id WHERE i.id=? GROUP BY i.id',[$id]);
        if(!$invoice){redirect_to('invoices');}
        if($_SERVER['REQUEST_METHOD']==='POST'){
            require_write(); csrf_check();
            try{
                $amount=(float)($_POST['amount']??0); $balance=(float)$invoice['total']-(float)$invoice['paid_amount'];
                $method=(string)($_POST['method']??'cash');
                if($amount<=0||$amount>$balance+0.001||!in_array($method,['cash','bank_transfer','online_gateway'],true)){throw new RuntimeException(tr('مبلغ یا روش پرداخت معتبر نیست.','Invalid payment amount or method.'));}
                $pdo=Database::pdo();$pdo->beginTransaction();
                Database::execute('INSERT INTO payments (invoice_id,amount,method,reference,received_by,received_at,notes) VALUES (?,?,?,?,?,NOW(),?)',[$id,$amount,$method,trim((string)($_POST['reference']??'')),Auth::user()['id'],trim((string)($_POST['notes']??''))]);
                $newPaid=(float)$invoice['paid_amount']+$amount;
                if($newPaid+0.001>=(float)$invoice['total']){Database::execute('UPDATE invoices SET status="paid",paid_at=NOW() WHERE id=?',[$id]);}
                log_activity('payment_record','invoice',$id,['amount'=>$amount,'method'=>$method]);$pdo->commit();
                flash('success',tr('پرداخت ثبت شد.','Payment recorded.'));redirect_to('invoice-detail',['id'=>$id]);
            }catch(Throwable $e){if(Database::pdo()->inTransaction()){Database::pdo()->rollBack();}flash('error',$e->getMessage());}
        }
        $payments=Database::fetchAll('SELECT p.*,a.username received_by_name FROM payments p LEFT JOIN admins a ON a.id=p.received_by WHERE p.invoice_id=? ORDER BY p.id DESC',[$id]);
        render('invoice_detail',compact('invoice','payments')+['pageTitle'=>$invoice['invoice_number']]);
        break;

    case 'invoice-delete':
        require_write(); csrf_check();
        $id = (int) ($_POST['id'] ?? 0);
        $invoice = Database::fetch('SELECT invoice_number FROM invoices WHERE id=?', [$id]);
        if ($invoice) {
            $pdo = Database::pdo(); $pdo->beginTransaction();
            try {
                Database::execute('DELETE FROM payments WHERE invoice_id=?', [$id]);
                Database::execute('DELETE FROM invoices WHERE id=?', [$id]);
                log_activity('invoice_delete', 'invoice', $id, ['number' => $invoice['invoice_number']]);
                $pdo->commit(); flash('success', tr('فاکتور حذف شد.', 'Invoice deleted.'));
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                flash('error', $exception->getMessage());
            }
        }
        redirect_to('invoices');

    case 'report':
        $report=[
            'today'=>(float)(Database::fetch('SELECT COALESCE(SUM(amount),0)c FROM payments WHERE DATE(received_at)=CURDATE()')['c']??0),
            'month'=>(float)(Database::fetch('SELECT COALESCE(SUM(amount),0)c FROM payments WHERE received_at>=DATE_FORMAT(CURDATE(),"%Y-%m-01")')['c']??0),
            'year'=>(float)(Database::fetch('SELECT COALESCE(SUM(amount),0)c FROM payments WHERE received_at>=MAKEDATE(YEAR(CURDATE()),1)')['c']??0),
        ];
        $overdue=Database::fetchAll('SELECT i.*,u.username,p.currency,COALESCE(SUM(pay.amount),0) paid_amount FROM invoices i JOIN ppp_users u ON u.id=i.local_user_id LEFT JOIN plans p ON p.id=i.plan_id LEFT JOIN payments pay ON pay.invoice_id=i.id WHERE i.status="overdue" GROUP BY i.id ORDER BY i.due_date');
        render('report',compact('report','overdue')+['pageTitle'=>tr('گزارش مالی','Financial report')]);
        break;

    case 'admins':
        require_superadmin();
        $admins=Database::fetchAll('SELECT id,username,full_name,role,language,is_active,created_at FROM admins ORDER BY username');
        render('admins',compact('admins')+['pageTitle'=>tr('مدیران پنل','Panel administrators')]);
        break;

    case 'admin-form':
        require_superadmin(); $id=max(0,(int)($_GET['id']??0));
        $admin=$id?Database::fetch('SELECT id,username,full_name,role,language,phone,is_active FROM admins WHERE id=?',[$id]):null;
        if($id&&!$admin){redirect_to('admins');}
        if($_SERVER['REQUEST_METHOD']==='POST'){
            csrf_check();
            try{
                $username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');$role=(string)($_POST['role']??'viewer');$language=(string)($_POST['language']??'fa');
                if(!preg_match('/^[A-Za-z0-9_.-]{3,100}$/',$username)||!in_array($role,['superadmin','operator','billing','viewer'],true)||!in_array($language,['fa','en'],true)||(!$id&&strlen($password)<10)){throw new RuntimeException(tr('اطلاعات مدیر معتبر نیست؛ رمز جدید حداقل ۱۰ نویسه باشد.','Invalid administrator details; new passwords need at least 10 characters.'));}
                if($id){
                    Database::execute('UPDATE admins SET username=?,full_name=?,role=?,language=?,phone=?,is_active=? WHERE id=?',[$username,trim((string)($_POST['full_name']??'')),$role,$language,trim((string)($_POST['phone']??'')),isset($_POST['is_active'])?1:0,$id]);
                    if($password!==''){if(strlen($password)<10){throw new RuntimeException(tr('رمز حداقل ۱۰ نویسه باشد.','Password must be at least 10 characters.'));}Database::execute('UPDATE admins SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$id]);}
                    log_activity('admin_update','admin',$id,['username'=>$username,'role'=>$role]);
                }else{
                    Database::execute('INSERT INTO admins (username,password_hash,full_name,role,language,phone,is_active,created_at) VALUES (?,?,?,?,?,?,?,NOW())',[$username,password_hash($password,PASSWORD_DEFAULT),trim((string)($_POST['full_name']??'')),$role,$language,trim((string)($_POST['phone']??'')),isset($_POST['is_active'])?1:0]);
                    $id=Database::id();log_activity('admin_create','admin',$id,['username'=>$username,'role'=>$role]);
                }
                flash('success',tr('مدیر ذخیره شد.','Administrator saved.'));redirect_to('admins');
            }catch(Throwable $e){flash('error',$e->getMessage());$admin=array_merge($admin??[],$_POST);}
        }
        render('admin_form',compact('admin','id')+['pageTitle'=>$id?tr('ویرایش مدیر','Edit administrator'):tr('افزودن مدیر','Add administrator')]);
        break;

    case 'activity':
        require_superadmin();
        $logs=Database::fetchAll('SELECT l.*,a.username admin_name FROM activity_logs l LEFT JOIN admins a ON a.id=l.admin_id ORDER BY l.id DESC LIMIT 250');
        render('activity',compact('logs')+['pageTitle'=>tr('گزارش فعالیت','Activity log')]);
        break;

    case 'system':
        require_superadmin();
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $directory = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $cronUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.example') . $directory . '/cron.php?token=' . rawurlencode((string) config('app', 'cron_token'));
        $diagnostics = [
            ['label' => 'PHP', 'value' => PHP_VERSION, 'ok' => PHP_VERSION_ID >= 80100],
            ['label' => 'PDO MySQL', 'value' => extension_loaded('pdo_mysql') ? tr('فعال','Enabled') : tr('غیرفعال','Disabled'), 'ok' => extension_loaded('pdo_mysql')],
            ['label' => 'cURL', 'value' => extension_loaded('curl') ? tr('فعال','Enabled') : tr('غیرفعال','Disabled'), 'ok' => extension_loaded('curl')],
            ['label' => 'OpenSSL', 'value' => extension_loaded('openssl') ? tr('فعال','Enabled') : tr('غیرفعال','Disabled'), 'ok' => extension_loaded('openssl')],
            ['label' => 'Timezone', 'value' => date_default_timezone_get(), 'ok' => true],
        ];
        render('system',compact('cronUrl','diagnostics')+['pageTitle'=>tr('وضعیت سیستم','System status')]);
        break;

    case 'maintenance-run':
        require_superadmin(); csrf_check();
        try {
            $result = run_maintenance();
            flash('success', tr('نگهداری دوره‌ای اجرا شد: ','Maintenance completed: ') . json_encode($result, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $exception) {
            flash('error', $exception->getMessage());
        }
        redirect_to('system');

    default:
        http_response_code(404);
        render('not_found', ['pageTitle' => '404']);
}
