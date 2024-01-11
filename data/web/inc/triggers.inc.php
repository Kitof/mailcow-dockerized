<?php
// handle iam authentication
if ($iam_provider){
  if (isset($_GET['iam_sso'])){
    // redirect for sso
    $redirect_uri = identity_provider('get-redirect', array('iam_provider' => $iam_provider));
    header('Location: ' . $redirect_uri);
    die();
  }
  if ($_SESSION['iam_token'] && $_SESSION['iam_refresh_token']) {
    // Session found, try to refresh
    $isRefreshed = identity_provider('refresh-token', array('iam_provider' => $iam_provider));

    if (!$isRefreshed){
      // Session could not be refreshed, clear and redirect to provider
      clear_session();
      $redirect_uri = identity_provider('get-redirect', array('iam_provider' => $iam_provider));
      header('Location: ' . $redirect_uri);
      die();
    }
  } elseif ($_GET['code'] && $_GET['state'] === $_SESSION['oauth2state']) {
    // Check given state against previously stored one to mitigate CSRF attack
    // Recieved access token in $_GET['code']
    // extract info and verify user
    identity_provider('verify-sso', array('iam_provider' => $iam_provider));
  }
}

// SSO Domain Admin
if (!empty($_GET['sso_token'])) {
  $username = domain_admin_sso('check', $_GET['sso_token']);

  if ($username !== false) {
    $_SESSION['mailcow_cc_username'] = $username;
    $_SESSION['mailcow_cc_role'] = 'domainadmin';
    header('Location: /mailbox');
  }
}

if (isset($_POST["verify_tfa_login"])) {
  if (verify_tfa_login($_SESSION['pending_mailcow_cc_username'], $_POST)) {
    $_SESSION['mailcow_cc_username'] = $_SESSION['pending_mailcow_cc_username'];
    $_SESSION['mailcow_cc_role'] = $_SESSION['pending_mailcow_cc_role'];
    $session_var_user_allowed = 'sogo-sso-user-allowed';
    $session_var_pass = 'sogo-sso-pass';
    // load master password
    $sogo_sso_pass = file_get_contents("/etc/sogo-sso/sogo-sso.pass");
    // register username and password in session
    $_SESSION[$session_var_user_allowed][] = $_SESSION['pending_mailcow_cc_username'];
    $_SESSION[$session_var_pass] = $sogo_sso_pass;
    unset($_SESSION['pending_mailcow_cc_username']);
    unset($_SESSION['pending_mailcow_cc_role']);
    unset($_SESSION['pending_tfa_methods']);

    $user_details = mailbox("get", "mailbox_details", $_SESSION['mailcow_cc_username']);
    if (intval($user_details['attributes']['sogo_access']) == 1) {
      header("Location: /SOGo/so/{$_SESSION['mailcow_cc_username']}");
      die();
    } else {
      header("Location: /user");
      die();
    }
  } else {
    unset($_SESSION['pending_mailcow_cc_username']);
    unset($_SESSION['pending_mailcow_cc_role']);
    unset($_SESSION['pending_tfa_methods']);
  }
}

if (isset($_GET["cancel_tfa_login"])) {
    unset($_SESSION['pending_mailcow_cc_username']);
    unset($_SESSION['pending_mailcow_cc_role']);
    unset($_SESSION['pending_tfa_methods']);

    header("Location: /");
}

if (isset($_POST["quick_release"])) {
	quarantine('quick_release', $_POST["quick_release"]);
}

if (isset($_POST["quick_delete"])) {
	quarantine('quick_delete', $_POST["quick_delete"]);
}

if (isset($_POST["login_user"]) && isset($_POST["pass_user"])) {
  $login_user = strtolower(trim($_POST["login_user"]));
  $as = check_login($login_user, $_POST["pass_user"]);

  if ($as == "admin") {
		$_SESSION['mailcow_cc_username'] = $login_user;
		$_SESSION['mailcow_cc_role'] = "admin";
		header("Location: /admin");
	}
	elseif ($as == "domainadmin") {
		$_SESSION['mailcow_cc_username'] = $login_user;
		$_SESSION['mailcow_cc_role'] = "domainadmin";
		header("Location: /mailbox");
	}
	elseif ($as == "user") {
		$_SESSION['mailcow_cc_username'] = $login_user;
		$_SESSION['mailcow_cc_role'] = "user";
    $http_parameters = explode('&', $_SESSION['index_query_string']);
    unset($_SESSION['index_query_string']);
    if (in_array('mobileconfig', $http_parameters)) {
        if (in_array('only_email', $http_parameters)) {
            header("Location: /mobileconfig.php?only_email");
            die();
        }
        header("Location: /mobileconfig.php");
        die();
    }

    $session_var_user_allowed = 'sogo-sso-user-allowed';
    $session_var_pass = 'sogo-sso-pass';
    // load master password
    $sogo_sso_pass = file_get_contents("/etc/sogo-sso/sogo-sso.pass");
    // register username and password in session
    $_SESSION[$session_var_user_allowed][] = $login_user;
    $_SESSION[$session_var_pass] = $sogo_sso_pass;

    $user_details = mailbox("get", "mailbox_details", $login_user);
    if (intval($user_details['attributes']['sogo_access']) == 1) {
      header("Location: /SOGo/so/{$login_user}");
      die();
    } else {
      header("Location: /user");
      die();
    }
	}
	elseif ($as != "pending") {
    unset($_SESSION['pending_mailcow_cc_username']);
    unset($_SESSION['pending_mailcow_cc_role']);
    unset($_SESSION['pending_tfa_methods']);
		unset($_SESSION['mailcow_cc_username']);
		unset($_SESSION['mailcow_cc_role']);
	}
}

if (isset($_SESSION['mailcow_cc_role']) && (isset($_SESSION['acl']['login_as']) && $_SESSION['acl']['login_as'] == "1")) {
	if (isset($_GET["duallogin"])) {
    $duallogin = html_entity_decode(rawurldecode($_GET["duallogin"]));
    if (filter_var($duallogin, FILTER_VALIDATE_EMAIL)) {
      if (!empty(mailbox('get', 'mailbox_details', $duallogin))) {
        $_SESSION["dual-login"]["username"] = $_SESSION['mailcow_cc_username'];
        $_SESSION["dual-login"]["role"]     = $_SESSION['mailcow_cc_role'];
        $_SESSION['mailcow_cc_username']    = $duallogin;
        $_SESSION['mailcow_cc_role']        = "user";
        header("Location: /user");
      }
    }
    else {
      if (!empty(domain_admin('details', $duallogin))) {
        $_SESSION["dual-login"]["username"] = $_SESSION['mailcow_cc_username'];
        $_SESSION["dual-login"]["role"]     = $_SESSION['mailcow_cc_role'];
        $_SESSION['mailcow_cc_username']    = $duallogin;
        $_SESSION['mailcow_cc_role']        = "domainadmin";
        header("Location: /user");
      }
    }
  }
}

if (isset($_SESSION['mailcow_cc_role'])) {
	if (isset($_POST["set_tfa"])) {
		set_tfa($_POST);
	}
	if (isset($_POST["unset_tfa_key"])) {
		unset_tfa_key($_POST);
	}
	if (isset($_POST["unset_fido2_key"])) {
		fido2(array("action" => "unset_fido2_key", "post_data" => $_POST));
	}
}
if (isset($_SESSION['mailcow_cc_role']) && $_SESSION['mailcow_cc_role'] == "admin" && !isset($_SESSION['mailcow_cc_api'])) {
  // TODO: Move file upload to API?
	if (isset($_POST["submit_main_logo"])) {
    if ($_FILES['main_logo']['error'] == 0) {
      customize('add', 'main_logo', $_FILES);
    }
    if ($_FILES['main_logo_dark']['error'] == 0) {
      customize('add', 'main_logo_dark', $_FILES);
    }
	}
	if (isset($_POST["reset_main_logo"])) {
    customize('delete', 'main_logo');
    customize('delete', 'main_logo_dark');
	}
  // Some actions will not be available via API
	if (isset($_POST["license_validate_now"])) {
		license('verify');
	}
  if (isset($_POST["admin_api"])) {
    if (isset($_POST["admin_api"]["ro"])) {
      admin_api('ro', 'edit', $_POST);
    }
    elseif (isset($_POST["admin_api"]["rw"])) {
      admin_api('rw', 'edit', $_POST);
    }
	}
  if (isset($_POST["admin_api_regen_key"])) {
    if (isset($_POST["admin_api_regen_key"]["ro"])) {
      admin_api('ro', 'regen_key', $_POST);
    }
    elseif (isset($_POST["admin_api_regen_key"]["rw"])) {
      admin_api('rw', 'regen_key', $_POST);
    }
	}
	if (isset($_POST["rspamd_ui"])) {
		rspamd_ui('edit', $_POST);
	}
	if (isset($_POST["mass_send"])) {
		sys_mail($_POST);
	}
}
?>
