<?php
/**
 * "Continue with Google" for login.php and register.php. Expects $googleRedirect
 * (where to land afterwards). Renders nothing until GOOGLE_WEB_CLIENT_ID is set.
 *
 * Google's button hands its ID token to onGoogleCredential, which submits it
 * through a normal POST form — csrf_autoinject() adds the CSRF field to it.
 */
require_once __DIR__ . '/../config/google.php';
if (GOOGLE_WEB_CLIENT_ID === '') {
    return;
}
?>
<div style="display:flex;align-items:center;gap:12px;margin:18px 0;color:var(--gray);">
  <span style="flex:1;height:1px;background:currentColor;opacity:.3;"></span>
  <span class="mono" style="font-size:13px;">or</span>
  <span style="flex:1;height:1px;background:currentColor;opacity:.3;"></span>
</div>
<form id="googleForm" method="post" action="<?= BASE_PATH ?>/google_login.php" hidden>
  <input type="hidden" name="credential">
  <input type="hidden" name="redirect" value="<?= htmlspecialchars($googleRedirect ?? '') ?>">
</form>
<div id="g_id_onload"
     data-client_id="<?= htmlspecialchars(GOOGLE_WEB_CLIENT_ID) ?>"
     data-callback="onGoogleCredential"
     data-auto_prompt="false"></div>
<div class="g_id_signin" data-type="standard" data-shape="pill" data-theme="outline"
     data-text="continue_with" data-size="large" data-logo_alignment="center"
     style="display:flex;justify-content:center;"></div>
<script>
  function onGoogleCredential(response) {
    var form = document.getElementById('googleForm');
    form.credential.value = response.credential;
    form.submit();
  }
</script>
<script src="https://accounts.google.com/gsi/client" async></script>
