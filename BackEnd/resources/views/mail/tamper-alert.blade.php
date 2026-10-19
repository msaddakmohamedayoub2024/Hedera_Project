<h1>Alerte de sécurité concernant vos choix de placement</h1>

<p>Bonjour,</p>

<p>Quelqu’un a tenté de modifier vos choix de placement.</p>

<p>
    L’empreinte actuelle des données ne correspond plus à celle enregistrée sur Hedera.
    <br>
    Empreinte enregistrée : <strong>{{ substr($oldFingerprint, 0, 12) }}…{{ substr($oldFingerprint, -8) }}</strong>
    <br>
    Nouvelle empreinte simulée : <strong>{{ substr($newFingerprint, 0, 12) }}…{{ substr($newFingerprint, -8) }}</strong>
</p>

<p>Date et heure : {{ $occurredAt->format('d/m/Y à H:i:s') }}</p>

<p><strong>SIMULATION DE DÉMONSTRATION :</strong> cette tentative a été simulée à des fins de démonstration. Vos choix enregistrés n’ont pas été modifiés.</p>

<p>
    Pour demander l’ouverture d’une enquête par le Ministère, vous pouvez déposer un signalement.
    Veuillez indiquer cette référence : <strong>{{ $reportReference }}</strong>.
</p>

<p>Conservez cet email comme justificatif de la simulation.</p>
