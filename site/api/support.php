<?php
/**
 * Help & Info API — the mobile half of contact.php and page.php.
 *
 *   GET  /api/support.php?slug=faq       -> one CMS page (about, faq, privacy…)
 *   GET  /api/support.php                -> the list of CMS pages
 *   POST /api/support.php?action=contact {name, email, subject, message}
 *
 * Both halves are public, exactly as they are on the website: a shopper should
 * be able to read the privacy policy or ask a question without an account.
 * Contact accepts a bearer token when there is one, only so a signed-in
 * shopper's message can be tied back to them in the admin inbox.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cms_service.php';
require_once __DIR__ . '/../includes/contact_service.php';

require_once __DIR__ . '/../includes/cors.php';
api_cors_origin();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $slug = trim((string) ($_GET['slug'] ?? ''));

    if ($slug !== '') {
        $page = cms_page_find($slug);
        if (!$page) {
            http_response_code(404);
            echo json_encode(['error' => 'Page not found']);
            exit;
        }
        echo json_encode([
            'slug'    => (string) $page['slug'],
            'title'   => (string) $page['title'],
            'content' => (string) ($page['content'] ?? ''),
        ]);
        exit;
    }

    $items = [];
    foreach (cms_page_list() as $row) {
        // Titles and slugs only — the list is a menu, not the reading itself.
        $items[] = [
            'slug'  => (string) $row['slug'],
            'title' => (string) $row['title'],
        ];
    }
    echo json_encode(['items' => $items, 'count' => count($items)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = $_GET['action'] ?? ($input['action'] ?? '');

if ($action !== 'contact') {
    http_response_code(422);
    echo json_encode(['error' => 'Unknown action. Use ?action=contact.']);
    exit;
}

$name    = trim((string) ($input['name'] ?? ''));
$email   = trim((string) ($input['email'] ?? ''));
$subject = trim((string) ($input['subject'] ?? ''));
$message = trim((string) ($input['message'] ?? ''));

// A signed-in shopper does not have to retype what we already know.
$viewerId = auth_user_id_from_bearer_token();
if ($viewerId !== null) {
    $me = user_find($viewerId);
    if ($me) {
        $name  = $name !== '' ? $name : (string) ($me['full_name'] ?: $me['username']);
        $email = $email !== '' ? $email : (string) $me['email'];
    }
}

if ($name === '' || $email === '' || $message === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Please fill in your name, email and message.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['error' => 'Please enter a valid email address.']);
    exit;
}

contact_create($name, $email, $subject !== '' ? $subject : 'Message from the app', $message);

echo json_encode(['message' => 'Thanks — we have got your message and will reply by email.']);
