<?php

// Auto-generated email-template translations for en_US.
// Base (Italian) lives in app/Support/SettingsMailTemplates.php; this file
// overrides subject+body per template. Placeholders/HTML/emoji preserved.

return [
    'admin_invitation' => [
        'subject' => '🎉 Invitation as Administrator',
        'body' => '<h2>Welcome to the team!</h2>
<p>Hi {{nome}} {{cognome}},</p>
<p>You\'ve been invited as an administrator on <strong>{{app_name}}</strong>.</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">Your access</h3>
    <p>As an administrator, you\'ll have access to:</p>
    <ul>
        <li>Book catalog management</li>
        <li>User and loan management</li>
        <li>System settings</li>
        <li>Reports and statistics</li>
    </ul>
</div>
<p><strong>To get started:</strong></p>
<ol>
    <li>Set your password by clicking the button below</li>
    <li>Log in to the admin panel</li>
</ol>
<p style="text-align: center; margin: 30px 0;">
    <a href="{{reset_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block; margin: 10px;">🔐 Set Password</a>
    <a href="{{dashboard_url}}" style="background-color: #3b82f6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block; margin: 10px;">📊 Admin Dashboard</a>
</p>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Important</strong></p>
    <p>The password setup link is valid for 24 hours.</p>
</div>
<p>Welcome to the team!</p>',
    ],
    'admin_new_registration' => [
        'subject' => '👤 New registration request',
        'body' => '<h2>New registration request</h2>
<p>A new user has requested access to Pinakes:</p>
<p><strong>User details:</strong></p>
<ul>
    <li>Name: {{nome}} {{cognome}}</li>
    <li>Email: {{email}}</li>
    <li>Card number: {{codice_tessera}}</li>
    <li>Request date: {{data_registrazione}}</li>
</ul>
<p><a href="{{admin_users_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Manage Users</a></p>',
    ],
    'admin_new_review' => [
        'subject' => '⭐ New review awaiting approval',
        'body' => '<h2>New review awaiting approval</h2>
<p>A new review has been submitted for the following book:</p>
<p><strong>Book:</strong> {{libro_titolo}}</p>
<div style="background-color: #fff7ed; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>Rating:</strong> {{stelle}} stars ⭐</p>
    <p><strong>User:</strong> {{utente_nome}} ({{utente_email}})</p>
    <p><strong>Review date:</strong> {{data_recensione}}</p>
    <p><strong>Title:</strong> {{titolo_recensione}}</p>
    <p><strong>Description:</strong></p>
    <p style="white-space: pre-line;">{{descrizione_recensione}}</p>
</div>
<p style="text-align: center;">
    <a href="{{link_approvazione}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">Manage Review</a>
</p>
<p><em>To approve or reject this review, log in to the admin panel.</em></p>',
    ],
    'copy_unavailable_user' => [
        'subject' => 'ℹ️ Update on your reservation',
        'body' => '<h2>Update on your reservation</h2>
<p>Hi {{utente_nome}},</p>
<p>We\'re writing to let you know that the copy reserved for your reservation of the following book is no longer available:</p>
<div style="background-color: #fffbeb; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>Book:</strong> {{libro_titolo}}</p>
    <p><strong>Reason:</strong> {{motivo}}</p>
</div>
<p>We\'re working to assign you another copy as soon as possible. If no other copies are available, your reservation will remain in the queue and we\'ll notify you as soon as the book becomes available again.</p>
<p>We apologize for the inconvenience.</p>
<p>Best regards,<br>The Library Team</p>',
    ],
    'loan_approved' => [
        'subject' => '✅ Your loan request has been approved!',
        'body' => '<h2>Your loan request has been approved!</h2>
<p>Hi {{utente_nome}},</p>
<p>We\'re happy to let you know that your loan request has been <strong>approved</strong>!</p>
<p><strong>Loan details:</strong></p>
<ul>
    <li>Book: {{libro_titolo}}</li>
    <li>Start date: {{data_inizio}}</li>
    <li>Due date: {{data_fine}}</li>
    <li>Duration: {{giorni_prestito}} days</li>
</ul>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Picking up your book</strong></p>
    <p>{{pickup_instructions}}</p>
</div>
<p><strong>Important:</strong> Remember to return the book by the due date. You\'ll receive a reminder a few days before it\'s due.</p>
<p>Happy reading!</p>',
    ],
    'loan_expiring_warning' => [
        'subject' => '⚠️ Your loan is about to expire',
        'body' => '<h2>Loan expiration reminder</h2>
<p>Hi {{utente_nome}},</p>
<p>Just a reminder that your loan is about to expire:</p>
<ul>
    <li>Book: {{libro_titolo}}</li>
    <li>Due date: {{data_scadenza}}</li>
    <li>Days remaining: {{giorni_rimasti}}</li>
</ul>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Action required</strong></p>
    <p>Please return the book by the due date, or contact us to arrange a renewal.</p>
</div>
<p>Thank you for your cooperation!</p>',
    ],
    'loan_overdue_admin' => [
        'subject' => 'Loan #{{prestito_id}} overdue',
        'body' => '<h2>Overdue loan</h2>
<p>Loan <strong>#{{prestito_id}}</strong> has entered the <strong>overdue</strong> status.</p>
<ul>
  <li><strong>Book:</strong> {{libro_titolo}}</li>
  <li><strong>User:</strong> {{utente_nome}} ({{utente_email}})</li>
  <li><strong>Loan date:</strong> {{data_prestito}}</li>
  <li><strong>Due date:</strong> {{data_scadenza}}</li>
</ul>
<p>Please reach out to the user and follow up on the return.</p>',
    ],
    'loan_overdue_notification' => [
        'subject' => '🚨 Overdue Loan - Action Required',
        'body' => '<h2>Overdue Loan</h2>
<p>Hi {{utente_nome}},</p>
<p>Your loan is overdue and the book must be returned immediately:</p>
<ul>
    <li>Book: {{libro_titolo}}</li>
    <li>Due date: {{data_scadenza}}</li>
    <li>Days overdue: {{giorni_ritardo}}</li>
</ul>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>❗️ Please Note</strong></p>
    <p>Failure to return the book may result in the suspension of your account and penalties.</p>
</div>
<p>Please return the book as soon as possible.</p>',
    ],
    'loan_pickup_cancelled' => [
        'subject' => '❌ Pickup Cancelled',
        'body' => '<h2>Pickup Cancelled</h2>
<p>Hi {{utente_nome}},</p>
<p>We\'re letting you know that the pickup for the following book has been cancelled:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Book:</strong> {{libro_titolo}}</p>
    <p><strong>Reason:</strong> {{motivo}}</p>
</div>
<p>The book has been made available for other members. If you\'d still like to borrow it, please feel free to submit a new loan request.</p>
<p>Best regards,<br>The Library Team</p>',
    ],
    'loan_pickup_expired' => [
        'subject' => '⏰ Pickup Time Expired',
        'body' => '<h2>Pickup Time Expired</h2>
<p>Hi {{utente_nome}},</p>
<p>Unfortunately, you didn\'t pick up the book within the allotted time.</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Book:</strong> {{libro_titolo}}</p>
    <p><strong>Pickup deadline:</strong> {{scadenza_ritiro}}</p>
</div>
<p>The loan has been automatically cancelled and the book has been made available to other members.</p>
<p>If you\'d still like to borrow this book, please submit a new loan request.</p>
<p>Best regards,<br>The Library Team</p>',
    ],
    'loan_pickup_ready' => [
        'subject' => '📦 Your book is ready for pickup!',
        'body' => '<h2>Your book is ready for pickup!</h2>
<p>Hi {{utente_nome}},</p>
<p>We\'re happy to let you know that your loan request has been <strong>approved</strong> and your book is ready for pickup!</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
    <p style="margin: 5px 0;"><strong>Loan period:</strong> {{data_inizio}} - {{data_fine}}</p>
    <p style="margin: 5px 0;"><strong>Duration:</strong> {{giorni_prestito}} days</p>
</div>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>⏰ Pickup deadline: {{scadenza_ritiro}}</strong></p>
    <p>Please pick up the book by this date, or the loan will be automatically canceled.</p>
</div>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 How to pick it up</strong></p>
    <p>{{pickup_instructions}}</p>
</div>
<p>Happy reading!</p>',
    ],
    'loan_rejected' => [
        'subject' => '❌ Your loan request was not approved',
        'body' => '<h2>Your loan request was not approved</h2>
<p>Hi {{utente_nome}},</p>
<p>We\'re sorry to inform you that your loan request for the book <strong>"{{libro_titolo}}"</strong> was not approved.</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Reason:</strong></p>
    <p>{{motivo_rifiuto}}</p>
</div>
<p>If you have any questions or would like more information, please don\'t hesitate to contact us.</p>
<p>Best regards,<br>The Library Team</p>',
    ],
    'loan_request_notification' => [
        'subject' => '📚 New Loan Request',
        'body' => '<h2>New Loan Request</h2>
<p>A new loan request has been received:</p>
<p><strong>Details:</strong></p>
<ul>
    <li>Book: {{libro_titolo}}</li>
    <li>User: {{utente_nome}} ({{utente_email}})</li>
    <li>Requested start date: {{data_inizio}}</li>
    <li>Requested end date: {{data_fine}}</li>
    <li>Request date: {{data_richiesta}}</li>
</ul>
<p><a href="{{approve_url}}" style="background-color: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Manage Request</a></p>',
    ],
    'loan_returned' => [
        'subject' => '✅ Return confirmed',
        'body' => '<h2>Return confirmed</h2>
<p>Hi {{utente_nome}},</p>
<p>We\'re confirming the return of the following book. Thank you!</p>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>Book:</strong> {{libro_titolo}}</p>
    <p><strong>Return date:</strong> {{data_restituzione}}</p>
</div>
<p>We hope you enjoyed the read. See you soon at the library!</p>
<p>Best regards,<br>The library team</p>',
    ],
    'reservation_book_available' => [
        'subject' => '📚 Your reserved book is ready for pickup!',
        'body' => '<h2>Your book is ready for pickup!</h2>
<p>Hi {{utente_nome}},</p>
<p>We\'re happy to let you know that the book you reserved is now available and ready for pickup:</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
    <p style="margin: 5px 0;"><strong>Author:</strong> {{libro_autore}}</p>
    <p style="margin: 5px 0;"><strong>ISBN:</strong> {{libro_isbn}}</p>
    <p style="margin: 5px 0;"><strong>Loan period:</strong> {{data_inizio}} - {{data_fine}}</p>
</div>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Next steps</strong></p>
    <p>Come to the library to pick up your book. Please bring a valid ID.</p>
</div>
<p style="text-align: center;">
    <a href="{{book_url}}" style="background-color: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📖 View Book</a>
    <a href="{{profile_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">👤 My Loans</a>
</p>
<p><em>Your reservation has been converted into a loan pending pickup confirmation.</em></p>',
    ],
    'reservation_cancelled' => [
        'subject' => '❌ Reservation cancelled',
        'body' => '<h2>Reservation cancelled</h2>
<p>Hi {{utente_nome}},</p>
<p>We\'re writing to let you know that your reservation for the following book has been cancelled:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Book:</strong> {{libro_titolo}}</p>
    <p><strong>Reason:</strong> {{motivo}}</p>
</div>
<p>If you\'d still like this book, you can place a new reservation at any time.</p>
<p>Best regards,<br>The Library Team</p>',
    ],
    'reservation_expired' => [
        'subject' => '⌛ Reservation Expired',
        'body' => '<h2>Reservation Expired</h2>
<p>Hi {{utente_nome}},</p>
<p>Your reservation for the following book has expired and was automatically closed:</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Book:</strong> {{libro_titolo}}</p>
    <p><strong>Expired on:</strong> {{data_scadenza}}</p>
</div>
<p>If you\'re still interested, you can place a new reservation at any time.</p>
<p>Best regards,<br>The Library Team</p>',
    ],
    'user_account_approved' => [
        'subject' => 'Account Approved - Welcome to the Library!',
        'body' => '<h2>Your account has been approved!</h2>
<p>Hi {{nome}} {{cognome}},</p>
<p>We\'re pleased to let you know that your account has been approved by an administrator.</p>
<p>You can now log in and start reserving books!</p>
<p><strong>Your account details:</strong></p>
<ul>
    <li>Email: {{email}}</li>
    <li>Card number: {{codice_tessera}}</li>
</ul>
<p><a href="{{login_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Log in now</a></p>
<p>Welcome to our digital library!</p>',
    ],
    'user_password_setup' => [
        'subject' => '🔐 Set Your Password',
        'body' => '<h2>Set Your Password</h2>
<p>Hi {{nome}} {{cognome}},</p>
<p>Your account on <strong>{{app_name}}</strong> has been created. To start using the system, you need to set your password.</p>
<div style="background-color: #f0f9ff; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #3b82f6;">
    <p><strong>🔑 Set up your account</strong></p>
    <p>Click the button below to set your password:</p>
</div>
<p style="text-align: center; margin: 30px 0;">
    <a href="{{reset_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block;">🔐 Set Password</a>
</p>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Important</strong></p>
    <p>This link is valid for 24 hours. If it expires, please contact an administrator to request a new one.</p>
</div>
<p>If you did not request this email, you can safely ignore it.</p>',
    ],
    'user_registration_pending' => [
        'subject' => 'Registration received - Pending approval',
        'body' => '<h2>Welcome {{nome}} {{cognome}}!</h2>
<p>Your registration request has been received successfully.</p>
<p><strong>Account details:</strong></p>
<ul>
    <li>Email: {{email}}</li>
    <li>Card number: {{codice_tessera}}</li>
    <li>Registration date: {{data_registrazione}}</li>
</ul>
{{sezione_verifica}}
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏳ Account pending approval</strong></p>
    <p>Your account is awaiting approval from an administrator.
    You will receive a confirmation email once your account has been activated.</p>
</div>
<p>Thank you for choosing Pinakes!</p>',
    ],
    'wishlist_book_available' => [
        'subject' => '📖 A book from your wishlist is now available!',
        'body' => '<h2>Good news! 📚</h2>
<p>Hi {{utente_nome}},</p>
<p>The book you added to your wishlist is now available for loan:</p>
<ul>
    <li><strong>Title:</strong> {{libro_titolo}}</li>
    <li><strong>Author:</strong> {{libro_autore}}</li>
    <li><strong>ISBN:</strong> {{libro_isbn}}</li>
    <li><strong>Available since:</strong> {{data_disponibilita}}</li>
</ul>
<div style="background: #ecfdf5; border-radius: 8px; padding: 16px; margin: 20px 0;">
    <p style="margin: 0 0 8px 0;">📍 The book is now available for immediate loan.</p>
    <p style="margin: 0;">⏰ We recommend reserving it right away before it\'s gone!</p>
</div>
<p style="text-align: center;">
    <a href="{{book_url}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📚 Reserve now</a>
    <a href="{{wishlist_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">❤️ Manage wishlist</a>
</p>
<p><em>📝 You can remove this book from your wishlist at any time.</em></p>',
    ],
];
