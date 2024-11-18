# Simple PHP Email Client

A lightweight web-based email client built with PHP, allowing users to connect to their email accounts via IMAP or POP3, read and manage emails, and send emails via SMTP.

## Features

- **Email Login:** Connect using IMAP or POP3 credentials.
- **Inbox Viewing:** See a list of your emails with subjects and senders.
- **Email Reading:** Open and read individual emails.
- **Attachments:** Download email attachments.
- **Email Sending:** Compose and send new emails via SMTP.
- **Email Deletion:** Remove unwanted emails from your inbox.
- **Responsive Design:** Built with Tailwind CSS.
- **Interactivity:** Uses Alpine.js for dynamic components.

## Requirements

- **PHP:** Version 7.4 or higher with `imap` and `openssl` extensions enabled.

## Usage

1. **Access the Application:**
   - Navigate to the application's URL in your web browser.
2. **Login:**
   - Enter your email server details and credentials.
   - Click **Login** to access your inbox.
3. **Manage Emails:**
   - **View Emails:** Browse your inbox and open emails.
   - **Send Emails:** Use the **Compose New Email** button to send messages.
   - **Delete Emails:** Remove emails you no longer need.
4. **Logout:**
   - Click the **Logout** button to end your session.

## Notes

- Ensure your server supports SSL/TLS if required by your email provider.
- For security, consider running the application over HTTPS.