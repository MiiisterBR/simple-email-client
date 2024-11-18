<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start output buffering
ob_start();

// Function to check if the user is logged in
function isLoggedIn() {
    return isset($_COOKIE['imap_host']) && isset($_COOKIE['mail_protocol']);
}

// Function to decode MIME-encoded text
function decode_imap_text($str) {
    $elements = imap_mime_header_decode($str);
    $decoded = '';
    foreach ($elements as $element) {
        $charset = strtoupper($element->charset);
        if ($charset == 'DEFAULT') {
            $decoded .= $element->text;
        } else {
            $decoded .= iconv($charset, 'UTF-8//TRANSLIT', $element->text);
        }
    }
    return $decoded;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // Store IMAP/POP3 credentials in cookies
    setcookie('imap_host', $_POST['imap_host'], time() + (86400 * 30), "/");
    setcookie('imap_port', $_POST['imap_port'], time() + (86400 * 30), "/");
    setcookie('imap_user', $_POST['imap_user'], time() + (86400 * 30), "/");
    setcookie('imap_pass', $_POST['imap_pass'], time() + (86400 * 30), "/");
    setcookie('imap_ssl', isset($_POST['imap_ssl']) ? '/ssl/novalidate-cert' : '/notls', time() + (86400 * 30), "/");
    setcookie('mail_protocol', $_POST['mail_protocol'], time() + (86400 * 30), "/");

    // Store SMTP credentials in cookies
    setcookie('smtp_host', $_POST['smtp_host'], time() + (86400 * 30), "/");
    setcookie('smtp_port', $_POST['smtp_port'], time() + (86400 * 30), "/");
    setcookie('smtp_user', $_POST['smtp_user'], time() + (86400 * 30), "/");
    setcookie('smtp_pass', $_POST['smtp_pass'], time() + (86400 * 30), "/");
    setcookie('smtp_secure', $_POST['smtp_secure'], time() + (86400 * 30), "/"); // اصلاح شد

    // Redirect to the same page to apply cookies
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    // Clear the cookies
    setcookie('imap_host', '', time() - 3600, "/");
    setcookie('imap_port', '', time() - 3600, "/");
    setcookie('imap_user', '', time() - 3600, "/");
    setcookie('imap_pass', '', time() - 3600, "/");
    setcookie('imap_ssl', '', time() - 3600, "/");
    setcookie('mail_protocol', '', time() - 3600, "/");

    setcookie('smtp_host', '', time() - 3600, "/");
    setcookie('smtp_port', '', time() - 3600, "/");
    setcookie('smtp_user', '', time() - 3600, "/");
    setcookie('smtp_pass', '', time() - 3600, "/");
    setcookie('smtp_secure', '', time() - 3600, "/");
    unset($_COOKIE);

    // Redirect to the same page to show the login form
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    // Clean output buffer to prevent any previous output
    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    if (!isLoggedIn()) {
        echo json_encode(['error' => 'You are not logged in']);
        exit;
    }

    switch ($_GET['action']) {
        case 'get_emails':
            fetchEmails();
            break;
        case 'read_email':
            if (isset($_GET['email_number'])) {
                readEmail(intval($_GET['email_number']));
            }
            break;
        case 'delete_email':
            if (isset($_GET['email_number'])) {
                deleteEmail(intval($_GET['email_number']));
            }
            break;
        case 'download_attachment':
            if (isset($_GET['email_number']) && isset($_GET['part_number'])) {
                downloadAttachment(intval($_GET['email_number']), intval($_GET['part_number']));
            }
            break;
    }
    exit;
}

// Handle sending email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'send_email') {
    // Clean output buffer to prevent any previous output
    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    if (!isLoggedIn()) {
        echo json_encode(['error' => 'You are not logged in']);
        exit;
    }

    $to = $_POST['to'];
    $subject = $_POST['subject'];
    $message = $_POST['message'];

    // Send the email
    $result = smtp_mail(
        $to,
        $subject,
        $message,
        $_COOKIE['smtp_host'],
        $_COOKIE['smtp_port'],
        $_COOKIE['smtp_user'],
        $_COOKIE['smtp_pass'],
        $_COOKIE['smtp_secure']
    );

    if ($result === true) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => $result]);
    }
    exit;
}

// Fetch emails from IMAP or POP3 server
function fetchEmails() {
    // Set the timeouts
    imap_timeout(IMAP_OPENTIMEOUT, 1200);
    imap_timeout(IMAP_READTIMEOUT, 1200);
    imap_timeout(IMAP_WRITETIMEOUT, 1200);

    $mailbox = '{' . $_COOKIE['imap_host'] . ':' . $_COOKIE['imap_port'] . '/' . $_COOKIE['mail_protocol'] . $_COOKIE['imap_ssl'] . '}INBOX';

    // Use '@' to suppress errors
    $inbox = @imap_open($mailbox, $_COOKIE['imap_user'], $_COOKIE['imap_pass'], OP_READONLY, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);

    if (!$inbox) {
        // Clean output buffer
        if (ob_get_length()) ob_clean();
        // Include error message
        echo json_encode(['error' => 'Cannot connect to mail server: ' . imap_last_error()]);
        exit;
    }

    $output = [];

    $numMessages = imap_num_msg($inbox);

    if ($numMessages > 0) {
        for ($i = $numMessages; $i >= 1; $i--) {
            $overview = imap_fetch_overview($inbox, $i, 0)[0];
            $output[] = [
                'number' => $i,
                'subject' => isset($overview->subject) ? decode_imap_text($overview->subject) : '(No Subject)',
                'from' => isset($overview->from) ? decode_imap_text($overview->from) : '',
                'date' => $overview->date,
                'seen' => (isset($overview->seen) && $overview->seen ? true : false),
                'has_attachment' => hasAttachments($inbox, $i),
            ];
        }
    }

    imap_close($inbox);

    // Clean output buffer
    if (ob_get_length()) ob_clean();

    echo json_encode($output);
}

// Read a specific email
function readEmail($email_number) {
    // Set the timeouts
    imap_timeout(IMAP_OPENTIMEOUT, 1200);
    imap_timeout(IMAP_READTIMEOUT, 1200);
    imap_timeout(IMAP_WRITETIMEOUT, 1200);

    $mailbox = '{' . $_COOKIE['imap_host'] . ':' . $_COOKIE['imap_port'] . '/' . $_COOKIE['mail_protocol'] . $_COOKIE['imap_ssl'] . '}INBOX';

    // Use '@' to suppress errors
    $inbox = @imap_open($mailbox, $_COOKIE['imap_user'], $_COOKIE['imap_pass']);

    if (!$inbox) {
        // Clean output buffer
        if (ob_get_length()) ob_clean();
        // Include error message
        echo json_encode(['error' => 'Cannot connect to mail server: ' . imap_last_error()]);
        exit;
    }

    $structure = imap_fetchstructure($inbox, $email_number);
    $body = getBody($inbox, $email_number, $structure);
    $attachments = getAttachments($inbox, $email_number, $structure);

    imap_close($inbox);

    // Clean output buffer
    if (ob_get_length()) ob_clean();

    echo json_encode(['body' => $body, 'attachments' => $attachments]);
}

// Delete an email
function deleteEmail($email_number) {
    $mailbox = '{' . $_COOKIE['imap_host'] . ':' . $_COOKIE['imap_port'] . '/' . $_COOKIE['mail_protocol'] . $_COOKIE['imap_ssl'] . '}INBOX';

    // Use '@' to suppress errors
    $inbox = @imap_open($mailbox, $_COOKIE['imap_user'], $_COOKIE['imap_pass']);

    if (!$inbox) {
        // Clean output buffer
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => 'Cannot connect to mail server: ' . imap_last_error()]);
        exit;
    }

    imap_delete($inbox, $email_number);
    imap_expunge($inbox);

    imap_close($inbox);

    // Clean output buffer
    if (ob_get_length()) ob_clean();

    echo json_encode(['success' => true]);
}

// Download attachment
function downloadAttachment($email_number, $part_number) {
    $mailbox = '{' . $_COOKIE['imap_host'] . ':' . $_COOKIE['imap_port'] . '/' . $_COOKIE['mail_protocol'] . $_COOKIE['imap_ssl'] . '}INBOX';

    // Use '@' to suppress errors
    $inbox = @imap_open($mailbox, $_COOKIE['imap_user'], $_COOKIE['imap_pass']);

    if (!$inbox) {
        exit('Cannot connect to mail server: ' . imap_last_error());
    }

    $structure = imap_fetchstructure($inbox, $email_number);

    $part = $structure->parts[$part_number - 1];
    $attachmentData = imap_fetchbody($inbox, $email_number, $part_number);

    // Decode attachment based on encoding
    if ($part->encoding == 3) { // BASE64
        $attachmentData = base64_decode($attachmentData);
    } elseif ($part->encoding == 4) { // QUOTED-PRINTABLE
        $attachmentData = quoted_printable_decode($attachmentData);
    }

    // Get the filename
    $filename = 'attachment';

    if ($part->ifdparameters) {
        foreach ($part->dparameters as $object) {
            if (strtolower($object->attribute) == 'filename') {
                $filename = $object->value;
            }
        }
    }

    // Send headers and output the attachment
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($attachmentData));

    echo $attachmentData;
    exit;
}

// Function to check for attachments
function hasAttachments($inbox, $email_number) {
    $structure = imap_fetchstructure($inbox, $email_number);
    if (isset($structure->parts) && count($structure->parts)) {
        foreach ($structure->parts as $part) {
            if ($part->ifdparameters) {
                return true;
            }
        }
    }
    return false;
}

// Function to get the email body
function getBody($inbox, $email_number, $structure) {
    $body = '';

    if ($structure->type == 0) { // Text email
        $body = imap_fetchbody($inbox, $email_number, 1);
        if ($structure->encoding == 3) {
            $body = base64_decode($body);
        } elseif ($structure->encoding == 4) {
            $body = quoted_printable_decode($body);
        }

        // Decode to UTF-8
        $charset = 'UTF-8';
        if ($structure->parameters) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) == 'charset') {
                    $charset = $param->value;
                    break;
                }
            }
        }
        $body = iconv($charset, 'UTF-8//TRANSLIT', $body);

    } else {
        // For multipart emails
        $body = getPart($inbox, $email_number, "TEXT/HTML");
        if ($body == "") {
            $body = getPart($inbox, $email_number, "TEXT/PLAIN");
        }
    }

    return $body;
}

// Helper function to get email parts
function getPart($inbox, $email_number, $mime_type, $structure = false, $partNumber = false) {
    if (!$structure) {
        $structure = imap_fetchstructure($inbox, $email_number);
    }
    if ($structure) {
        if ($mime_type == getMimeType($structure)) {
            if (!$partNumber) {
                $partNumber = "1";
            }
            $text = imap_fetchbody($inbox, $email_number, $partNumber);
            if ($structure->encoding == 3) {
                $text = base64_decode($text);
            } elseif ($structure->encoding == 4) {
                $text = quoted_printable_decode($text);
            }

            // Decode to UTF-8
            $charset = 'UTF-8';
            if ($structure->parameters) {
                foreach ($structure->parameters as $param) {
                    if (strtolower($param->attribute) == 'charset') {
                        $charset = $param->value;
                        break;
                    }
                }
            }
            $text = iconv($charset, 'UTF-8//TRANSLIT', $text);

            return $text;
        }

        // multipart
        if ($structure->type == 1) {
            foreach ($structure->parts as $index => $subStruct) {
                $prefix = "";
                if ($partNumber) {
                    $prefix = $partNumber . ".";
                }
                $data = getPart($inbox, $email_number, $mime_type, $subStruct, $prefix . ($index + 1));
                if ($data) {
                    return $data;
                }
            }
        }
    }
    return "";
}

// Helper function to get MIME type
function getMimeType($structure) {
    $primaryMimes = ["TEXT", "MULTIPART", "MESSAGE", "APPLICATION", "AUDIO", "IMAGE", "VIDEO", "OTHER"];
    if ($structure->subtype) {
        return $primaryMimes[(int)$structure->type] . "/" . $structure->subtype;
    }
    return "TEXT/PLAIN";
}

// Function to get attachments
function getAttachments($inbox, $email_number, $structure) {
    $attachments = [];

    if (isset($structure->parts) && count($structure->parts)) {
        for ($i = 0; $i < count($structure->parts); $i++) {
            $part = $structure->parts[$i];
            if ($part->ifdparameters) {
                foreach ($part->dparameters as $object) {
                    if (strtolower($object->attribute) == 'filename') {
                        $attachment = [
                            'filename' => $object->value,
                            'encoding' => $part->encoding,
                            'part_number' => $i + 1,
                        ];
                        $attachments[] = $attachment;
                    }
                }
            }
        }
    }

    return $attachments;
}

// Function to send email via SMTP
function smtp_mail($to, $subject, $message, $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_secure) {
    $newline = "\r\n";

    // Create email headers
    $headers = '';
    $headers .= 'From: ' . $smtp_user . $newline;
    $headers .= 'Reply-To: ' . $smtp_user . $newline;
    $headers .= 'MIME-Version: 1.0' . $newline;
    $headers .= 'Content-Type: text/html; charset=UTF-8' . $newline;

    // Construct the full email message
    $contentMail = 'To: ' . $to . $newline;
    $contentMail .= 'Subject: ' . $subject . $newline;
    $contentMail .= $headers . $newline;
    $contentMail .= $message . $newline;

    // Adjust SMTP host for SSL
    if ($smtp_secure == 'ssl') {
        $smtp_host = 'ssl://' . $smtp_host;
    }

    // Open a socket connection to the SMTP server
    $socket = fsockopen($smtp_host, $smtp_port, $errno, $errstr, 30);
    if (!$socket) {
        return "Cannot connect to SMTP server: $errstr ($errno)";
    }

    // Get server response
    $response = smtp_get_response($socket);

    // Initiate SMTP conversation
    fputs($socket, "EHLO " . $_SERVER['SERVER_NAME'] . $newline);
    $response = smtp_get_response($socket);

    // Start TLS if required
    if ($smtp_secure == 'tls') {
        fputs($socket, "STARTTLS" . $newline);
        $response = smtp_get_response($socket);

        // Enable crypto for TLS
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        // Re-initiate EHLO after starting TLS
        fputs($socket, "EHLO " . $_SERVER['SERVER_NAME'] . $newline);
        $response = smtp_get_response($socket);
    }

    // Authenticate using AUTH LOGIN
    fputs($socket, "AUTH LOGIN" . $newline);
    $response = smtp_get_response($socket);

    fputs($socket, base64_encode($smtp_user) . $newline);
    $response = smtp_get_response($socket);

    fputs($socket, base64_encode($smtp_pass) . $newline);
    $response = smtp_get_response($socket);

    // Specify the sender
    fputs($socket, "MAIL FROM: <" . $smtp_user . ">" . $newline);
    $response = smtp_get_response($socket);

    // Specify the recipient
    fputs($socket, "RCPT TO: <" . $to . ">" . $newline);
    $response = smtp_get_response($socket);

    // Send the DATA command to begin message input
    fputs($socket, "DATA" . $newline);
    $response = smtp_get_response($socket);

    // Send the email content
    fputs($socket, $contentMail . $newline . "." . $newline);
    $response = smtp_get_response($socket);

    // Close the SMTP connection
    fputs($socket, "QUIT" . $newline);
    fclose($socket);

    // Check if the email was sent successfully
    if (strpos($response, '250') !== false) {
        return true;
    } else {
        return "Failed to send email: $response";
    }
}

// Helper function to get SMTP server response
function smtp_get_response($socket) {
    $data = "";
    while ($str = fgets($socket, 515)) {
        $data .= $str;
        // Break the loop if the response ends
        if (substr($str, 3, 1) == " ") {
            break;
        }
    }
    return $data;
}

// End output buffering and flush output
if (ob_get_length()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Simple Email Client</title>
    <!-- Include Tailwind CSS from CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Include Alpine.js for interactivity -->
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        /* Toast notification styles */
        #toast {
            visibility: hidden;
            min-width: 250px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 16px;
            position: fixed;
            z-index: 50;
            left: 50%;
            bottom: 30px;
            transform: translateX(-50%);
            font-size: 17px;
        }

        #toast.show {
            visibility: visible;
            animation: fadein 0.5s, fadeout 0.5s 2.5s;
        }

        @keyframes fadein {
            from {bottom: 0; opacity: 0;} 
            to {bottom: 30px; opacity: 1;}
        }

        @keyframes fadeout {
            from {bottom: 30px; opacity: 1;} 
            to {bottom: 0; opacity: 0;}
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Toast Notification Container -->
    <div id="toast"></div>

    <?php if (!isLoggedIn()): ?>
    <!-- Login Form -->
    <div class="container mx-auto mt-10">
        <form method="post" class="bg-white p-6 rounded shadow-md" id="login-form">
            <h2 class="text-2xl mb-4 text-center">Email Login</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- IMAP/POP3 Server Details -->
                <div>
                    <h3 class="text-xl mb-2 font-semibold">Mail Server Settings</h3>
                    <div class="mb-4">
                        <label class="block">Protocol:</label>
                        <select name="mail_protocol" id="mail_protocol" class="border p-2 w-full h-[2.7rem]">
                            <option value="imap">IMAP</option>
                            <option value="pop3">POP3</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block">Server:</label>
                        <input type="text" name="imap_host" id="imap_host" class="border p-2 w-full" required>
                    </div>
                    <div class="mb-4">
                        <label class="block">Port:</label>
                        <input type="text" name="imap_port" id="imap_port" class="border p-2 w-full" value="993" required>
                    </div>
                    <div class="mb-4">
                        <label class="block">Username:</label>
                        <input type="text" name="imap_user" id="imap_user" class="border p-2 w-full" required>
                    </div>
                    <div class="mb-4">
                        <label class="block">Password:</label>
                        <input type="password" name="imap_pass" id="imap_pass" class="border p-2 w-full" required>
                    </div>
                    <div class="mb-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="imap_ssl" id="imap_ssl" class="form-checkbox" checked>
                            <span class="ml-2">Use SSL</span>
                        </label>
                    </div>
                    <p class="text-sm text-gray-600 mb-4">
                        If left empty, the SMTP settings will default to the above settings.
                    </p>
                </div>
                <!-- SMTP Server Details -->
                <div>
                    <h3 class="text-xl mb-2 font-semibold">SMTP Settings</h3>
                    <div class="mb-4">
                        <label class="block">Protocol:</label>
                        <input type="text" disabled name="smtp_protocol" id="smtp_protocol" class="border p-2 w-full bg-gray-200" value="SMTP">
                    </div>
                    <div class="mb-4">
                        <label class="block">SMTP Server:</label>
                        <input type="text" name="smtp_host" id="smtp_host" class="border p-2 w-full">
                    </div>
                    <div class="mb-4">
                        <label class="block">SMTP Port:</label>
                        <input type="text" name="smtp_port" id="smtp_port" class="border p-2 w-full" value="465">
                    </div>
                    <div class="mb-4">
                        <label class="block">SMTP Username:</label>
                        <input type="text" name="smtp_user" id="smtp_user" class="border p-2 w-full">
                    </div>
                    <div class="mb-4">
                        <label class="block">SMTP Password:</label>
                        <input type="password" name="smtp_pass" id="smtp_pass" class="border p-2 w-full">
                    </div>
                    <div class="mb-4">
                        <label class="block">SMTP Security Type:</label>
                        <select name="smtp_secure" id="smtp_secure" class="border p-2 w-full">
                            <option value="ssl">SSL</option>
                            <option value="tls">TLS</option>
                            <option value="">No Security</option>
                        </select>
                    </div>
                </div>
            </div>
            <button type="submit" name="login" class="bg-blue-500 text-white px-4 py-2 rounded w-full mt-4">Login</button>
        </form>
    </div>

    <script>
        // JavaScript to copy IMAP fields to SMTP fields if SMTP fields are empty
        document.addEventListener('DOMContentLoaded', function() {
            const imapFields = ['imap_host', 'imap_port', 'imap_user', 'imap_pass'];
            const smtpFields = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass'];

            imapFields.forEach((imapField, index) => {
                const imapInput = document.getElementById(imapField);
                const smtpInput = document.getElementById(smtpFields[index]);

                imapInput.addEventListener('input', function() {
                    if (smtpInput.value.trim() === '') {
                        smtpInput.value = imapInput.value;
                    }
                });
            });

            // Copy IMAP SSL to SMTP Security Type if SMTP field is empty
            const imapSslCheckbox = document.getElementById('imap_ssl');
            const smtpSecureSelect = document.getElementById('smtp_secure');

            imapSslCheckbox.addEventListener('change', function() {
                if (smtpSecureSelect.value === '') {
                    smtpSecureSelect.value = imapSslCheckbox.checked ? 'ssl' : '';
                }
            });
        });
    </script>

    <?php else: ?>
    <!-- Email Compose Section (Accordion) -->
    <div class="container mx-auto mt-10" x-data="{ open: false }">
        <button @click="open = !open" class="bg-blue-500 text-white px-4 py-2 rounded w-full text-left">
            Compose New Email
        </button>
        <div x-show="open" class="bg-white p-6 rounded shadow-md mt-2">
            <form id="send-email-form">
                <div class="mb-4">
                    <label class="block">To:</label>
                    <input type="email" name="to" class="border p-2 w-full" required>
                </div>
                <div class="mb-4">
                    <label class="block">Subject:</label>
                    <input type="text" name="subject" class="border p-2 w-full" required>
                </div>
                <div class="mb-4">
                    <label class="block">Message:</label>
                    <textarea name="message" class="border p-2 w-full" rows="5" required></textarea>
                </div>
                <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded">Send</button>
                <!-- Added this line -->
                <div id="send-email-message" class="mt-2 text-center"></div>
            </form>
        </div>
    </div>
    <!-- Display Emails -->
    <div class="container mx-auto mt-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-2xl">Inbox</h2>
            <div>
                <!-- Button to toggle auto-fetch -->
                <button id="toggle-autofetch" class="bg-gray-500 text-white px-4 py-2 rounded mr-2">Disable Auto-Fetch</button>
                <a href="?logout" class="bg-yellow-500 text-white px-4 py-2 rounded mr-2 no-underline">Logout</a>
            </div>
        </div>
        <div id="emails" class="bg-white p-6 rounded shadow-md">
            Loading emails...
        </div>
    </div>

    <!-- Email Content Modal -->
    <div id="email-modal" class="fixed z-10 inset-0 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            <div class="bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full p-6">
                <!-- Moved Close button to the top -->
                <button onclick="closeModal()" class="mb-4 bg-red-500 text-white px-4 py-2 rounded">Close</button>
                <div id="email-content"></div>
            </div>
        </div>
    </div>

    <script>
        let emails = [];
        let lastEmailCount = 0;
        let autoFetchInterval = null;
        let autoFetchEnabled = true;

        // Function to fetch emails from the server
        function fetchEmails() {
            console.log('Fetching emails...');
            fetch('?action=get_emails')
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        showToast(data.error, 'error');
                    } else {
                        emails = data;
                        displayEmails();
                        // Check if new emails have arrived
                        if (emails.length > lastEmailCount) {
                            showToast('You have a new email!', 'success');
                        }
                        lastEmailCount = emails.length;
                    }
                })
                .catch(error => {
                    showToast('Error fetching emails: ' + error, 'error');
                });
        }

        // Function to display emails in the inbox
        function displayEmails() {
            let emailsDiv = document.getElementById('emails');
            emailsDiv.innerHTML = '';
            if (emails.length === 0) {
                emailsDiv.innerHTML = '<p>No emails found.</p>';
                return;
            }
            emails.forEach(email => {
                let emailDiv = document.createElement('div');
                emailDiv.className = 'border-b py-2 flex flex-col md:flex-row justify-between items-start md:items-center';
                emailDiv.innerHTML = `
                    <div class="flex-grow">
                        <strong>${email.subject}</strong> from ${email.from} at ${email.date}
                    </div>
                    <div class="flex-shrink-0 mt-2 md:mt-0">
                        ${email.has_attachment ? '<span class="text-green-500 mr-2">📎</span>' : ''}
                        <button onclick="readEmail(${email.number})" class="bg-blue-500 text-white px-2 py-1 rounded ml-2">View</button>
                        <button onclick="deleteEmail(${email.number})" class="bg-red-500 text-white px-2 py-1 rounded ml-2">Delete</button>
                    </div>
                `;
                emailsDiv.appendChild(emailDiv);
            });
        }

        // Function to read a specific email
        function readEmail(emailNumber) {
            fetch(`?action=read_email&email_number=${emailNumber}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        showToast(data.error, 'error');
                    } else {
                        let contentDiv = document.getElementById('email-content');
                        contentDiv.innerHTML = `
                            <div class="mb-4">${data.body}</div>
                        `;

                        // Display attachments if any
                        if (data.attachments.length > 0) {
                            contentDiv.innerHTML += '<h3 class="font-semibold">Attachments:</h3><ul>';
                            data.attachments.forEach(attachment => {
                                contentDiv.innerHTML += `
                                    <li>
                                        <a href="?action=download_attachment&email_number=${emailNumber}&part_number=${attachment.part_number}" class="text-blue-500">${attachment.filename}</a>
                                    </li>
                                `;
                            });
                            contentDiv.innerHTML += '</ul>';
                        }

                        // Show the modal
                        document.getElementById('email-modal').classList.remove('hidden');
                    }
                })
                .catch(error => {
                    showToast('Error reading email: ' + error, 'error');
                });
        }

        // Function to delete an email
        function deleteEmail(emailNumber) {
            if (confirm('Are you sure you want to delete this email?')) {
                fetch(`?action=delete_email&email_number=${emailNumber}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            showToast(data.error, 'error');
                        } else {
                            showToast('Email deleted successfully!', 'success');
                            fetchEmails();
                        }
                    })
                    .catch(error => {
                        showToast('Error deleting email: ' + error, 'error');
                    });
            }
        }

        // Function to close the modal
        function closeModal() {
            document.getElementById('email-modal').classList.add('hidden');
        }

        // Event listener for the email sending form
        document.getElementById('send-email-form').addEventListener('submit', function(e) {
            e.preventDefault();
            let formData = new FormData(this);
            formData.append('action', 'send_email');

            // Display 'Sending email...' message
            let messageDiv = document.getElementById('send-email-message');
            messageDiv.innerHTML = '<span class="text-blue-500">Sending email...</span>';

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        messageDiv.innerHTML = '<span class="text-green-500">Email sent successfully!</span>';
                        this.reset();
                    } else {
                        messageDiv.innerHTML = '<span class="text-red-500">Error sending email: ' + data.error + '</span>';
                    }
                })
                .catch(error => {
                    messageDiv.innerHTML = '<span class="text-red-500">Error sending email: ' + error + '</span>';
                });
        });

        // Function to show toast notifications
        function showToast(message, type) {
            let toast = document.getElementById('toast');
            toast.className = '';
            toast.innerText = message;
            toast.style.backgroundColor = type === 'success' ? '#4CAF50' : '#f44336';
            toast.classList.add('show');
            setTimeout(function() {
                toast.classList.remove('show');
            }, 3000);
        }

        // Functions to start and stop auto-fetch
        function startAutoFetch() {
            if (!autoFetchInterval) {
                autoFetchInterval = setInterval(fetchEmails, 10000);
                autoFetchEnabled = true;
                document.getElementById('toggle-autofetch').innerText = 'Disable Auto-Fetch';
            }
        }

        function stopAutoFetch() {
            if (autoFetchInterval) {
                clearInterval(autoFetchInterval);
                autoFetchInterval = null;
                autoFetchEnabled = false;
                document.getElementById('toggle-autofetch').innerText = 'Enable Auto-Fetch';
            }
        }

        // Event listener for the auto-fetch toggle button
        document.getElementById('toggle-autofetch').addEventListener('click', function() {
            if (autoFetchEnabled) {
                stopAutoFetch();
                showToast('Auto-Fetch Disabled', 'success');
            } else {
                startAutoFetch();
                showToast('Auto-Fetch Enabled', 'success');
            }
        });

        // Initially fetch emails and start auto-fetch
        fetchEmails();
        startAutoFetch();

    </script>
    <?php endif; ?>
</body>
</html>