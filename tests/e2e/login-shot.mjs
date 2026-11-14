import { chromium } from 'playwright';
const b = await chromium.launch();
for (const [name, vp] of [['m390', { width: 390, height: 844 }], ['m360', { width: 360, height: 640 }]]) {
  const c = await b.newContext({ viewport: vp, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const p = await c.newPage();
  await p.goto((process.env.BASE_URL ?? 'http://localhost:8080') + '/login');
  await p.waitForSelector('#password');
  await p.click('button[aria-label="Mostrar contraseña"]');
  await p.fill('#password', 'demo1234');
  console.log(name, 'overflowX:', await p.evaluate(() => document.documentElement.scrollWidth > innerWidth), 'pwType:', await p.getAttribute('#password', 'type'));
  await p.screenshot({ path: `tests/e2e/screenshots/login-${name}.png` });
  await c.close();
}
await b.close();
