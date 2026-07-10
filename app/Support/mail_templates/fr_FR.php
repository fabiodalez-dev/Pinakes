<?php

// Auto-generated email-template translations for fr_FR.
// Base (Italian) lives in app/Support/SettingsMailTemplates.php; this file
// overrides subject+body per template. Placeholders/HTML/emoji preserved.

return [
    'admin_invitation' => [
        'subject' => '🎉 Invitation en tant qu\'administrateur',
        'body' => '<h2>Bienvenue dans l\'équipe !</h2>
<p>Bonjour {{nome}} {{cognome}},</p>
<p>Vous avez été invité(e) en tant qu\'administrateur sur <strong>{{app_name}}</strong>.</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">Vos accès</h3>
    <p>En tant qu\'administrateur, vous aurez accès à :</p>
    <ul>
        <li>Gestion du catalogue de livres</li>
        <li>Gestion des utilisateurs et des prêts</li>
        <li>Paramètres du système</li>
        <li>Rapports et statistiques</li>
    </ul>
</div>
<p><strong>Pour commencer :</strong></p>
<ol>
    <li>Définissez votre mot de passe en cliquant sur le bouton ci-dessous</li>
    <li>Connectez-vous au panneau d\'administration</li>
</ol>
<p style="text-align: center; margin: 30px 0;">
    <a href="{{reset_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block; margin: 10px;">🔐 Définir le mot de passe</a>
    <a href="{{dashboard_url}}" style="background-color: #3b82f6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block; margin: 10px;">📊 Tableau de bord Admin</a>
</p>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Important</strong></p>
    <p>Le lien pour définir votre mot de passe est valable 24 heures.</p>
</div>
<p>Bienvenue dans l\'équipe !</p>',
    ],
    'admin_new_registration' => [
        'subject' => '👤 Nouvelle demande d\'inscription',
        'body' => '<h2>Nouvelle demande d\'inscription</h2>
<p>Un nouvel utilisateur a demandé l\'accès à Pinakes :</p>
<p><strong>Détails de l\'utilisateur :</strong></p>
<ul>
    <li>Nom : {{nome}} {{cognome}}</li>
    <li>E-mail : {{email}}</li>
    <li>Numéro de carte : {{codice_tessera}}</li>
    <li>Date de la demande : {{data_registrazione}}</li>
</ul>
<p><a href="{{admin_users_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Gérer les utilisateurs</a></p>',
    ],
    'admin_new_review' => [
        'subject' => '⭐ Nouvel avis à approuver',
        'body' => '<h2>Nouvel avis à approuver</h2>
<p>Un nouvel avis a été reçu pour le livre :</p>
<p><strong>Livre :</strong> {{libro_titolo}}</p>
<div style="background-color: #fff7ed; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>Note :</strong> {{stelle}} étoiles ⭐</p>
    <p><strong>Utilisateur :</strong> {{utente_nome}} ({{utente_email}})</p>
    <p><strong>Date de l\'avis :</strong> {{data_recensione}}</p>
    <p><strong>Titre :</strong> {{titolo_recensione}}</p>
    <p><strong>Description :</strong></p>
    <p style="white-space: pre-line;">{{descrizione_recensione}}</p>
</div>
<p style="text-align: center;">
    <a href="{{link_approvazione}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">Gérer l\'avis</a>
</p>
<p><em>Pour approuver ou refuser cet avis, connectez-vous au panneau d\'administration.</em></p>',
    ],
    'copy_unavailable_user' => [
        'subject' => 'ℹ️ Mise à jour concernant votre réservation',
        'body' => '<h2>Mise à jour concernant votre réservation</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Nous vous informons que l\'exemplaire réservé pour votre demande concernant le livre suivant n\'est plus disponible :</p>
<div style="background-color: #fffbeb; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>Livre :</strong> {{libro_titolo}}</p>
    <p><strong>Motif :</strong> {{motivo}}</p>
</div>
<p>Nous faisons notre possible pour vous attribuer un autre exemplaire dès que possible. Si aucun autre exemplaire n\'est disponible, votre réservation restera en file d\'attente et nous vous préviendrons dès que le livre sera de nouveau disponible.</p>
<p>Nous nous excusons pour la gêne occasionnée.</p>
<p>Cordialement,<br>L\'équipe de la bibliothèque</p>',
    ],
    'loan_approved' => [
        'subject' => '✅ Votre demande de prêt a été approuvée !',
        'body' => '<h2>Votre demande de prêt a été approuvée !</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Nous avons le plaisir de vous informer que votre demande de prêt a été <strong>approuvée</strong> !</p>
<p><strong>Détails du prêt :</strong></p>
<ul>
    <li>Livre : {{libro_titolo}}</li>
    <li>Date de début du prêt : {{data_inizio}}</li>
    <li>Date d\'échéance : {{data_fine}}</li>
    <li>Durée : {{giorni_prestito}} jours</li>
</ul>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Retrait du livre</strong></p>
    <p>{{pickup_instructions}}</p>
</div>
<p><strong>Important :</strong> N\'oubliez pas de rendre le livre avant la date d\'échéance. Vous recevrez un rappel quelques jours avant l\'échéance.</p>
<p>Bonne lecture !</p>',
    ],
    'loan_expiring_warning' => [
        'subject' => '⚠️ Votre prêt arrive bientôt à échéance',
        'body' => '<h2>Rappel d\'échéance de prêt</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Nous vous rappelons que votre prêt arrive bientôt à échéance :</p>
<ul>
    <li>Livre : {{libro_titolo}}</li>
    <li>Date d\'échéance : {{data_scadenza}}</li>
    <li>Jours restants : {{giorni_rimasti}}</li>
</ul>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Action requise</strong></p>
    <p>Merci de restituer le livre avant la date d\'échéance ou de nous contacter pour un éventuel renouvellement.</p>
</div>
<p>Merci pour votre collaboration !</p>',
    ],
    'loan_overdue_admin' => [
        'subject' => 'Prêt #{{prestito_id}} en retard',
        'body' => '<h2>Prêt en retard</h2>
<p>Le prêt <strong>#{{prestito_id}}</strong> est passé au statut <strong>en retard</strong>.</p>
<ul>
  <li><strong>Livre :</strong> {{libro_titolo}}</li>
  <li><strong>Utilisateur :</strong> {{utente_nome}} ({{utente_email}})</li>
  <li><strong>Date de prêt :</strong> {{data_prestito}}</li>
  <li><strong>Date d\'échéance :</strong> {{data_scadenza}}</li>
</ul>
<p>Veuillez intervenir pour contacter l\'utilisateur et le relancer pour la restitution.</p>',
    ],
    'loan_overdue_notification' => [
        'subject' => '🚨 Prêt en retard - Action requise',
        'body' => '<h2>Prêt en retard</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Votre prêt est arrivé à échéance et doit être restitué immédiatement :</p>
<ul>
    <li>Livre : {{libro_titolo}}</li>
    <li>Date d\'échéance : {{data_scadenza}}</li>
    <li>Jours de retard : {{giorni_ritardo}}</li>
</ul>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>❗️ Attention</strong></p>
    <p>Le non-retour du livre peut entraîner la suspension de votre compte ainsi que des pénalités.</p>
</div>
<p>Nous vous prions de bien vouloir restituer le livre dans les plus brefs délais.</p>',
    ],
    'loan_pickup_cancelled' => [
        'subject' => '❌ Retrait annulé',
        'body' => '<h2>Retrait annulé</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Nous vous informons que le retrait du livre suivant a été annulé :</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Livre :</strong> {{libro_titolo}}</p>
    <p><strong>Motif :</strong> {{motivo}}</p>
</div>
<p>Le livre a été remis à disposition des autres usagers. Si vous souhaitez toujours emprunter ce livre, nous vous invitons à effectuer une nouvelle demande de prêt.</p>
<p>Cordialement,<br>L\'équipe de la bibliothèque</p>',
    ],
    'loan_pickup_expired' => [
        'subject' => '⏰ Délai de retrait expiré',
        'body' => '<h2>Délai de retrait expiré</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Malheureusement, vous n\'avez pas retiré le livre dans le délai imparti.</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Livre :</strong> {{libro_titolo}}</p>
    <p><strong>Date limite de retrait :</strong> {{scadenza_ritiro}}</p>
</div>
<p>Le prêt a été automatiquement annulé et le livre a été remis à disposition des autres usagers.</p>
<p>Si vous souhaitez toujours emprunter ce livre, n\'hésitez pas à effectuer une nouvelle demande de prêt.</p>
<p>Cordialement,<br>L\'équipe de la bibliothèque</p>',
    ],
    'loan_pickup_ready' => [
        'subject' => '📦 Votre livre est prêt à être récupéré !',
        'body' => '<h2>Votre livre est prêt à être récupéré !</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Nous avons le plaisir de vous informer que votre demande de prêt a été <strong>approuvée</strong> et que le livre est prêt à être récupéré !</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
    <p style="margin: 5px 0;"><strong>Période de prêt :</strong> {{data_inizio}} - {{data_fine}}</p>
    <p style="margin: 5px 0;"><strong>Durée :</strong> {{giorni_prestito}} jours</p>
</div>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b;">
    <p><strong>⏰ Date limite de retrait : {{scadenza_ritiro}}</strong></p>
    <p>Veuillez récupérer le livre avant cette date, faute de quoi le prêt sera automatiquement annulé.</p>
</div>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Comment récupérer votre livre</strong></p>
    <p>{{pickup_instructions}}</p>
</div>
<p>Bonne lecture !</p>',
    ],
    'loan_rejected' => [
        'subject' => '❌ Votre demande de prêt n\'a pas été approuvée',
        'body' => '<h2>Votre demande de prêt n\'a pas été approuvée</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Nous sommes désolés de vous informer que votre demande de prêt pour le livre <strong>« {{libro_titolo}} »</strong> n\'a pas été approuvée.</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Motif :</strong></p>
    <p>{{motivo_rifiuto}}</p>
</div>
<p>Si vous avez des questions ou souhaitez obtenir plus d\'informations, n\'hésitez pas à nous contacter.</p>
<p>Cordialement,<br>L\'équipe de la bibliothèque</p>',
    ],
    'loan_request_notification' => [
        'subject' => '📚 Nouvelle demande de prêt',
        'body' => '<h2>Nouvelle demande de prêt</h2>
<p>Une nouvelle demande de prêt a été reçue :</p>
<p><strong>Détails :</strong></p>
<ul>
    <li>Livre : {{libro_titolo}}</li>
    <li>Utilisateur : {{utente_nome}} ({{utente_email}})</li>
    <li>Date de début demandée : {{data_inizio}}</li>
    <li>Date de fin demandée : {{data_fine}}</li>
    <li>Date de la demande : {{data_richiesta}}</li>
</ul>
<p><a href="{{approve_url}}" style="background-color: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Gérer la demande</a></p>',
    ],
    'loan_returned' => [
        'subject' => '✅ Retour confirmé',
        'body' => '<h2>Retour confirmé</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Nous vous confirmons le retour de l\'ouvrage suivant. Merci !</p>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>Livre :</strong> {{libro_titolo}}</p>
    <p><strong>Date de retour :</strong> {{data_restituzione}}</p>
</div>
<p>Nous espérons que cette lecture vous a plu. À très bientôt à la bibliothèque !</p>
<p>Cordialement,<br>L\'équipe de la bibliothèque</p>',
    ],
    'reservation_book_available' => [
        'subject' => '📚 Livre réservé prêt à être retiré !',
        'body' => '<h2>Votre livre est prêt à être retiré !</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Nous avons le plaisir de vous informer que le livre que vous aviez réservé est désormais disponible et prêt à être retiré :</p>
<div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
    <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
    <p style="margin: 5px 0;"><strong>Auteur :</strong> {{libro_autore}}</p>
    <p style="margin: 5px 0;"><strong>ISBN :</strong> {{libro_isbn}}</p>
    <p style="margin: 5px 0;"><strong>Période de prêt :</strong> {{data_inizio}} - {{data_fine}}</p>
</div>
<div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #10b981;">
    <p><strong>📦 Prochaines étapes</strong></p>
    <p>Rendez-vous à la bibliothèque pour retirer le livre. Munissez-vous d\'une pièce d\'identité.</p>
</div>
<p style="text-align: center;">
    <a href="{{book_url}}" style="background-color: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📖 Voir le livre</a>
    <a href="{{profile_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">👤 Mes prêts</a>
</p>
<p><em>La réservation a été convertie en prêt, en attente de confirmation du retrait.</em></p>',
    ],
    'reservation_cancelled' => [
        'subject' => '❌ Réservation annulée',
        'body' => '<h2>Réservation annulée</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Nous vous informons que votre réservation pour le livre suivant a été annulée :</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Livre :</strong> {{libro_titolo}}</p>
    <p><strong>Motif :</strong> {{motivo}}</p>
</div>
<p>Si vous souhaitez toujours emprunter ce livre, vous pouvez effectuer une nouvelle réservation à tout moment.</p>
<p>Cordialement,<br>L\'équipe de la bibliothèque</p>',
    ],
    'reservation_expired' => [
        'subject' => '⌛ Réservation expirée',
        'body' => '<h2>Réservation expirée</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Votre réservation pour le livre suivant a expiré et a été automatiquement clôturée :</p>
<div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
    <p><strong>Livre :</strong> {{libro_titolo}}</p>
    <p><strong>Expirée le :</strong> {{data_scadenza}}</p>
</div>
<p>Si vous êtes toujours intéressé(e), vous pouvez effectuer une nouvelle réservation à tout moment.</p>
<p>Cordialement,<br>L\'équipe de la bibliothèque</p>',
    ],
    'user_account_approved' => [
        'subject' => 'Compte approuvé - Bienvenue à la bibliothèque !',
        'body' => '<h2>Votre compte a été approuvé !</h2>
<p>Bonjour {{nome}} {{cognome}},</p>
<p>Nous avons le plaisir de vous informer que votre compte a été approuvé par un administrateur.</p>
<p>Vous pouvez désormais vous connecter au système et commencer à réserver des livres !</p>
<p><strong>Détails de votre compte :</strong></p>
<ul>
    <li>Email : {{email}}</li>
    <li>Numéro de carte : {{codice_tessera}}</li>
</ul>
<p><a href="{{login_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Se connecter</a></p>
<p>Bienvenue dans notre bibliothèque numérique !</p>',
    ],
    'user_password_setup' => [
        'subject' => '🔐 Définissez votre mot de passe',
        'body' => '<h2>Définissez votre mot de passe</h2>
<p>Bonjour {{nome}} {{cognome}},</p>
<p>Votre compte sur <strong>{{app_name}}</strong> a été créé. Pour commencer à utiliser le système, vous devez définir votre mot de passe.</p>
<div style="background-color: #f0f9ff; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #3b82f6;">
    <p><strong>🔑 Configurez votre compte</strong></p>
    <p>Cliquez sur le bouton ci-dessous pour définir votre mot de passe :</p>
</div>
<p style="text-align: center; margin: 30px 0;">
    <a href="{{reset_url}}" style="background-color: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-size: 16px; display: inline-block;">🔐 Définir le mot de passe</a>
</p>
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏰ Important</strong></p>
    <p>Ce lien est valable 24 heures. S\'il a expiré, contactez un administrateur pour en recevoir un nouveau.</p>
</div>
<p>Si vous n\'êtes pas à l\'origine de cette demande, vous pouvez ignorer cet e-mail.</p>',
    ],
    'user_registration_pending' => [
        'subject' => 'Inscription reçue - En attente d\'approbation',
        'body' => '<h2>Bienvenue {{nome}} {{cognome}} !</h2>
<p>Votre demande d\'inscription a bien été reçue.</p>
<p><strong>Détails du compte :</strong></p>
<ul>
    <li>Email : {{email}}</li>
    <li>Numéro de carte : {{codice_tessera}}</li>
    <li>Date d\'inscription : {{data_registrazione}}</li>
</ul>
{{sezione_verifica}}
<div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
    <p><strong>⏳ Compte en attente d\'approbation</strong></p>
    <p>Votre compte est en attente d\'approbation par un administrateur.
    Vous recevrez un email de confirmation dès que votre compte aura été activé.</p>
</div>
<p>Merci d\'avoir choisi Pinakes !</p>',
    ],
    'wishlist_book_available' => [
        'subject' => '📖 Un livre de votre liste de souhaits est désormais disponible !',
        'body' => '<h2>Bonne nouvelle ! 📚</h2>
<p>Bonjour {{utente_nome}},</p>
<p>Le livre que vous avez ajouté à votre liste de souhaits est maintenant disponible pour l\'emprunt :</p>
<ul>
    <li><strong>Titre :</strong> {{libro_titolo}}</li>
    <li><strong>Auteur :</strong> {{libro_autore}}</li>
    <li><strong>ISBN :</strong> {{libro_isbn}}</li>
    <li><strong>Date de disponibilité :</strong> {{data_disponibilita}}</li>
</ul>
<div style="background: #ecfdf5; border-radius: 8px; padding: 16px; margin: 20px 0;">
    <p style="margin: 0 0 8px 0;">📍 Le livre est désormais disponible pour un emprunt immédiat.</p>
    <p style="margin: 0;">⏰ Nous vous conseillons de le réserver dès maintenant avant qu\'il ne soit plus disponible !</p>
</div>
<p style="text-align: center;">
    <a href="{{book_url}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📚 Réserver maintenant</a>
    <a href="{{wishlist_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">❤️ Gérer ma liste de souhaits</a>
</p>
<p><em>📝 Vous pouvez retirer ce livre de votre liste de souhaits à tout moment.</em></p>',
    ],
];
