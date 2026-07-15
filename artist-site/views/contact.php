<section class="page-hero">
    <p class="eyebrow">Inquiries</p>
    <h1>Contact the Studio</h1>
    <p>For catalog documentation, curatorial questions, commissions, trade inquiries, or studio availability.</p>
</section>
<section class="section contact-panel">
    <div style="min-height: auto; padding: 24px;">
        <h2 style="font-size: clamp(20px, 1.8vw, 26px); margin-bottom: 20px; font-family: var(--serif-display); font-weight: normal; color: var(--ink); margin-top: 0;">Send Message</h2>
        
        <?php if (!empty($success)): ?>
            <div style="background: #f1fcf4; border: 1px solid #a3e2b4; color: #1b5e20; padding: 10px; margin-bottom: 15px; font-size: 14px; min-height: auto;">
                Message sent. Thank you.
            </div>
        <?php elseif (!empty($error)): ?>
            <div style="background: #fdf2f2; border: 1px solid #f8b4b4; color: #c81e1e; padding: 10px; margin-bottom: 15px; font-size: 14px; min-height: auto;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST" style="display: flex; flex-direction: column; gap: 12px;">
            <input type="hidden" name="action" value="contact_submit">
            
            <!-- Honeypot anti-spam (hidden) -->
            <div style="display: none; min-height: auto; padding: 0; border: none; background: transparent;">
                <label for="website">Leave blank</label>
                <input type="text" name="website" id="website" autocomplete="off">
            </div>

            <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                <label for="contact-name" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;">Name</label>
                <input type="text" name="name" id="contact-name" value="<?= e($submittedName ?? '') ?>" required placeholder="Your name" style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                <label for="contact-email" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;">Email</label>
                <input type="email" name="email" id="contact-email" value="<?= e($submittedEmail ?? '') ?>" required placeholder="your@email.com" style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                <label for="contact-subject" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;">Subject</label>
                <input type="text" name="subject" id="contact-subject" value="<?= e($submittedSubject ?? $subject) ?>" style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%;">
            </div>

            <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                <label for="contact-message" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;">Message</label>
                <textarea name="message" id="contact-message" required placeholder="Your message..." style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%; resize: vertical; min-height: 80px;"><?= e($submittedMessage ?? '') ?></textarea>
            </div>

            <button type="submit" class="button" onmouseover="this.style.background='var(--red)'; this.style.borderColor='var(--red)';" onmouseout="this.style.background='var(--ink)'; this.style.borderColor='var(--ink)';" style="cursor: pointer; justify-content: center; min-height: 36px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; width: 100%; border-radius: 0; transition: background 0.18s, border-color 0.18s;">Send Message</button>
        </form>
    </div>
    <div>
        <h2>Collector Notes</h2>
        <p>Works are original hand-painted acrylic paintings. Certificates of authenticity and professional shipping from Spain can be documented for each acquisition.</p>
    </div>
    <div>
        <h2>Profiles</h2>
        <div class="social-links" aria-label="Social and marketplace profiles">
            <?php foreach ($site["social"] as $label => $url): ?>
                <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>