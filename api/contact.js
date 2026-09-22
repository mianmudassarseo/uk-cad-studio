const RECIPIENT_DEFAULT = 'raotipusultaan@gmail.com';
const FROM_DEFAULT = 'UK CAD Studio <onboarding@resend.dev>';
const MIN_FILL_SECONDS = 3;

function clean(value, max = 2000) {
  if (typeof value !== 'string') return '';
  return value.trim().slice(0, max);
}

function validEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function parseBody(req) {
  if (req.body && typeof req.body === 'object' && !Buffer.isBuffer(req.body)) {
    return req.body;
  }

  if (typeof req.body !== 'string') return {};

  const type = String(req.headers['content-type'] || '').toLowerCase();

  if (type.includes('application/json')) {
    try { return JSON.parse(req.body); } catch (_) { return {}; }
  }

  if (type.includes('application/x-www-form-urlencoded')) {
    return Object.fromEntries(new URLSearchParams(req.body));
  }

  try { return JSON.parse(req.body); } catch (_) { return {};
  }
}

module.exports = async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');

  if (req.method !== 'POST') {
    res.setHeader('Allow', 'POST');
    return res.status(405).json({ ok: false, message: 'This endpoint only accepts form submissions.' });
  }

  const apiKey = process.env.RESEND_API_KEY;
  const recipient = process.env.CONTACT_TO || RECIPIENT_DEFAULT;
  const from = process.env.CONTACT_FROM || FROM_DEFAULT;

  if (!apiKey) {
    console.error('Contact form: RESEND_API_KEY is not configured.');
    return res.status(500).json({
      ok: false,
      message: 'Email delivery is not configured yet. Please email us directly at raotipusultaan@gmail.com.'
    });
  }

  const body = parseBody(req);

  const name = clean(body.name, 120);
  const email = clean(body.email, 254);
  const phone = clean(body.phone, 80);
  const postcode = clean(body.postcode, 40);
  const service = clean(body.service, 160) || 'General enquiry';
  const message = clean(body.message, 5000);
  const consent = clean(body.consent, 20);
  const website = clean(body.website, 250);
  const page = clean(body.page, 500);
  const ts = Number(body.ts || 0);

  // Honeypot: bots often fill hidden fields.
  if (website) {
    return res.status(200).json({ ok: true, message: 'Thank you. Your enquiry has been received.' });
  }

  if (!name || !validEmail(email) || message.length < 10 || consent !== 'yes') {
    return res.status(422).json({
      ok: false,
      message: 'Please check the required fields and try again.'
    });
  }

  // Basic speed trap. If timestamp is missing, do not block legitimate no-JS submissions.
  if (ts > 0 && Math.floor(Date.now() / 1000) - ts < MIN_FILL_SECONDS) {
    return res.status(429).json({
      ok: false,
      message: 'Please wait a moment and submit the form again.'
    });
  }

  const safe = {
    name: escapeHtml(name),
    email: escapeHtml(email),
    phone: escapeHtml(phone || 'Not provided'),
    postcode: escapeHtml(postcode || 'Not provided'),
    service: escapeHtml(service),
    message: escapeHtml(message).replace(/\n/g, '<br>'),
    page: escapeHtml(page || 'Not provided')
  };

  const subject = `New UK CAD Studio enquiry — ${service}`.slice(0, 180);
  const text = [
    'New UK CAD Studio enquiry',
    '',
    `Full name: ${name}`,
    `Email: ${email}`,
    `Phone: ${phone || 'Not provided'}`,
    `Project postcode: ${postcode || 'Not provided'}`,
    `Service required: ${service}`,
    `Page: ${page || 'Not provided'}`,
    '',
    'Project details:',
    message
  ].join('\n');

  const html = `
    <div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;color:#0f172a;line-height:1.55">
      <h2 style="margin:0 0 18px">New UK CAD Studio enquiry</h2>
      <table style="width:100%;border-collapse:collapse;margin-bottom:20px">
        <tr><td style="padding:7px 10px;font-weight:700;width:180px">Full name</td><td style="padding:7px 10px">${safe.name}</td></tr>
        <tr><td style="padding:7px 10px;font-weight:700">Email</td><td style="padding:7px 10px">${safe.email}</td></tr>
        <tr><td style="padding:7px 10px;font-weight:700">Phone</td><td style="padding:7px 10px">${safe.phone}</td></tr>
        <tr><td style="padding:7px 10px;font-weight:700">Project postcode</td><td style="padding:7px 10px">${safe.postcode}</td></tr>
        <tr><td style="padding:7px 10px;font-weight:700">Service required</td><td style="padding:7px 10px">${safe.service}</td></tr>
        <tr><td style="padding:7px 10px;font-weight:700">Submitted from</td><td style="padding:7px 10px">${safe.page}</td></tr>
      </table>
      <h3 style="margin:0 0 8px">Project details</h3>
      <div style="padding:14px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc">${safe.message}</div>
      <p style="margin-top:18px;color:#64748b;font-size:13px">Reply to this email to respond directly to ${safe.name}.</p>
    </div>`;

  try {
    const response = await fetch('https://api.resend.com/emails', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${apiKey}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        from,
        to: [recipient],
        subject,
        reply_to: email,
        text,
        html
      })
    });

    if (!response.ok) {
      const errorText = await response.text();
      console.error('Contact form: Resend failed', response.status, errorText.slice(0, 1000));
      return res.status(502).json({
        ok: false,
        message: 'Sorry, your message could not be sent. Please email us directly at raotipusultaan@gmail.com.'
      });
    }

    return res.status(200).json({
      ok: true,
      message: 'Thank you. Your enquiry has been sent successfully. We will reply within one working day.'
    });
  } catch (error) {
    console.error('Contact form error:', error && error.message ? error.message : error);
    return res.status(500).json({
      ok: false,
      message: 'Sorry, there was a network problem. Please email us directly at raotipusultaan@gmail.com.'
    });
  }
};
