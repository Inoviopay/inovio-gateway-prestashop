import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  // Payment flows hit a live gateway; give them room but never hang forever.
  timeout: 180000,
  expect: { timeout: 20000 },
  // Serial: these share one shop, one cart and one gateway.
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['html', { outputFolder: 'report', open: 'never' }]],
  use: {
    /*
     * Cardinal's ACS is a PUBLIC origin; its redirect back to our shop on a
     * PRIVATE IP is exactly the public->private transition Chrome's Local
     * Network Access blocks. Confirmed empirically: the OTP flow completes
     * (StepUp -> creq -> TermURL -> TermRedirection) and then the return hits
     * net::ERR_BLOCKED_BY_LOCAL_NETWORK_ACCESS_CHECKS.
     *
     * This is a browser policy about address space, not a defect in the
     * module and not a TLS requirement. Disable those checks so the test can
     * exercise the real return leg. A shop on a public hostname never hits it.
     */
    launchOptions: {
      args: [
        '--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessSendPreflights,PrivateNetworkAccessRespectPreflightResults',
      ],
    },
    baseURL: process.env.PS_URL || 'http://192.168.86.30:8095',
    // Evidence: a screenshot at every step plus video and trace on failure.
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
    actionTimeout: 30000,
    ignoreHTTPSErrors: true,
  },
});
