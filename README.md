# Sales Calls CRM

A smart, lightweight CRM designed specifically for sales teams managing cold calls. Built with PHP and MySQL, it offers intuitive lead management, call tracking, and intelligent import features.

## Features

- **User authentication** – Secure signup/login with password hashing.
- **Lead management** – Add, edit, view, and delete leads. Search and filter by status or keyword.
- **Call logging** – Log calls with outcomes, duration, and follow-up dates.
- **Smart import** – Upload CSV files with auto-detection of phone and email columns. Duplicate prevention and error reporting.
- **Inline editing** – Double-click any field in the leads table to edit quickly. Status changes update instantly via AJAX.
- **Quick actions** – Dropdown menu for each lead: view details, edit, log a call, add a quick note, or delete.
- **Bulk operations** – Select multiple leads to delete in bulk, or delete all leads with confirmation.
- **Follow-up reminders** – Daily email reminders for pending follow-ups (requires cron job).
- **Dashboard** – Overview of total leads, new leads, interested prospects, and calls made today.

## Tech Stack

- **Backend**: PHP (vanilla, no framework), MySQL
- **Frontend**: HTML, CSS, JavaScript (vanilla, no jQuery)
- **AJAX**: For inline updates and quick actions
- **Database**: MySQL with PDO for secure queries

## Installation

### Local Development

1. Clone the repository:
   ```bash
   git clone https://github.com/nick-laniyi/salescall-crm.git
   cd salescalls-crm
Set up a local web server (Apache/Nginx) pointing to the project folder. Ensure mod_rewrite is enabled if you plan to use clean URLs later.

Create a MySQL database and import the schema from database/schema.sql (if you have it; otherwise use the SQL provided in the README).

Copy includes/config.example.php to includes/config.php and fill in your database credentials.

Set the document root to the project folder (or configure your virtual host). For example, with Apache:

apache
<VirtualHost *:80>
    ServerName salescalls.local
    DocumentRoot /path/to/salescalls-crm
</VirtualHost>
Access the CRM at http://salescalls.local and register a new user.

Production Deployment
Upload all files to your web server.

Create a MySQL database and user.

Update includes/config.php with production credentials.

Run the schema SQL to create tables.

Set up a cron job for daily follow-up emails:

text
0 8 * * * /usr/bin/php /path/to/salescalls-crm/cron/send-reminders.php
File Structure
text
salescalls-crm/
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
├── cron/
│   └── send-reminders.php
├── includes/
│   ├── auth.php
│   ├── config.example.php
│   ├── config.php (ignored by git)
│   ├── footer.php
│   └── header.php
├── .gitignore
├── dashboard.php
├── download_sample.php
├── import.php
├── index.php
├── lead.php
├── leads.php
├── log-call.php
├── login.php
├── logout.php
├── profile.php
├── quick-status.php
├── register.php
└── README.md
Configuration
Database: Edit includes/config.php with your DB details.

Email: For follow-up reminders, configure your server's mail() or use SMTP in cron/send-reminders.php.

Contributing
Contributions are welcome! Feel free to open issues or submit pull requests. Please follow existing code style and include tests where applicable.

License
This project is open source and available under the MIT License.

Acknowledgements
Built as a portfolio project to demonstrate full-stack PHP development, AJAX interactions, and smart data handling. Inspired by the need for a simple, no-frills cold call CRM.

text

---

## 📄 LICENSE (MIT)

```markdown
MIT License

Copyright (c) 2026 [Nicholas Olaniyi]

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.