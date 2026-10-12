import request from 'supertest';
import { describe, expect, it } from 'vitest';
import { app } from './helpers.js';

describe('web UI served by the same app', () => {
  it('serves the shell at / and for deep links', async () => {
    for (const path of ['/', '/companies/acme/servers', '/anything/else']) {
      const res = await request(app).get(path);
      expect(res.status, path).toBe(200);
      expect(res.text).toContain('<div id="app">');
    }
  });

  it('serves static assets', async () => {
    expect((await request(app).get('/app.js')).headers['content-type']).toMatch(/javascript/);
    expect((await request(app).get('/styles.css')).status).toBe(200);
  });

  it('never serves the SPA shell for unknown API routes', async () => {
    const res = await request(app).get('/api/does-not-exist');
    expect(res.status).toBe(401);
    expect(res.headers['content-type']).toMatch(/json/);
  });

  it('sends security headers and no-store on the API', async () => {
    const res = await request(app).get('/api/me');
    expect(res.headers['content-security-policy']).toContain("script-src 'self'");
    expect(res.headers['cache-control']).toBe('no-store');
    expect(res.headers['x-powered-by']).toBeUndefined();
  });
});
