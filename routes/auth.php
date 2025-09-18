<?php
$router->get('/login', function() {
  $err = $_GET['e'] ?? '';
  $body = "<h1>Login</h1>"
        . ($err ? "<p class='err'>".h($err)."</p>" : "")
        . "<form method='post' action='".h(url_for('/login'))."'>"
        . csrf_field()
        . "<label>Email <input name='email' type='email' required></label>"
        . "<label>Password <input name='password' type='password' required></label>"
        . "<button>Login</button></form>";
  render('Login', $body);
});

$router->post('/login', function() {
  global $pdo;
  post_only();
  $email = trim($_POST['email'] ?? ''); $pass  = $_POST['password'] ?? '';
  $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND is_active=1");
  $stmt->execute([$email]); $u = $stmt->fetch();
  if ($u && password_verify($pass, $u['password_hash'])) {
    session_regenerate_id(true);
    $_SESSION['user'] = ['id'=>$u['id'], 'email'=>$u['email'], 'role'=>$u['role']];
    header('Location: ' . url_for('/')); exit;
  }
  header('Location: ' . url_for('/login') . '&e=Invalid%20credentials'); // non-pretty falls back to /?r=/login
  exit;
});

$router->get('/logout', function() {
  if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    $cp = session_get_cookie_params();
    setcookie(session_name(), '', [
      'expires'  => time()-3600, 'path' => '/',
      'domain'   => $cp['domain'] ?? ($_SERVER['HTTP_HOST'] ?? ''),
      'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
      'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_destroy();
  }
  header('Location: ' . url_for('/')); exit;
});
