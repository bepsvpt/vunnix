#!/usr/bin/env node
/**
 * Vunnix Screenshot Capture Script (D131)
 *
 * Uses Playwright headless Chromium to capture screenshots of web pages
 * for UI adjustment tasks. The executor calls this after making changes
 * to capture before/after screenshots.
 *
 * Usage:
 *   node capture-screenshot.js <url> <output-path> [options]
 *
 * Arguments:
 *   url          — The page URL to screenshot (e.g., http://localhost:5173/dashboard)
 *   output-path  — Where to save the PNG (e.g., /tmp/screenshot.png)
 *
 * Options:
 *   --width <n>      — Viewport width in pixels (default: 1280)
 *   --height <n>     — Viewport height in pixels (default: 720)
 *   --full-page      — Capture the full scrollable page (default: false)
 *   --wait <ms>      — Wait after page load before capture (default: 2000)
 *   --timeout <ms>   — Navigation timeout (default: 30000)
 *   --start-server   — Start a dev server before capturing (expects npm run dev)
 *   --server-port <n> — Port to wait for when starting server (default: 5173)
 *
 * Exit codes:
 *   0 — Screenshot captured successfully
 *   1 — Error (URL unreachable, timeout, Playwright error)
 *
 * Output:
 *   Prints JSON to stdout: { "path": "<output-path>", "width": N, "height": N, "bytes": N }
 *   Logs to stderr for debugging.
 *
 * @see §3.4 Task Dispatcher & Task Executor
 * @see §6.7 Executor Image
 */

const { chromium } = require('playwright');
const { spawn } = require('child_process');
const { stat } = require('fs/promises');
const net = require('net');

// ── Argument parsing ────────────────────────────────────────────────

function parseArgs(argv) {
    const args = argv.slice(2);

    if (args.length < 2) {
        console.error('Usage: capture-screenshot.js <url> <output-path> [options]');
        console.error('');
        console.error('Options:');
        console.error('  --width <n>        Viewport width (default: 1280)');
        console.error('  --height <n>       Viewport height (default: 720)');
        console.error('  --full-page        Capture full scrollable page');
        console.error('  --wait <ms>        Wait after load (default: 2000)');
        console.error('  --timeout <ms>     Navigation timeout (default: 30000)');
        console.error('  --start-server     Start dev server before capture');
        console.error('  --server-port <n>  Port for dev server (default: 5173)');
        process.exit(1);
    }

    const opts = {
        url: args[0],
        outputPath: args[1],
        width: 1280,
        height: 720,
        fullPage: false,
        waitMs: 2000,
        timeoutMs: 30000,
        startServer: false,
        serverPort: 5173,
    };

    for (let i = 2; i < args.length; i++) {
        switch (args[i]) {
            case '--width':
                opts.width = parseInt(args[++i], 10);
                break;
            case '--height':
                opts.height = parseInt(args[++i], 10);
                break;
            case '--full-page':
                opts.fullPage = true;
                break;
            case '--wait':
                opts.waitMs = parseInt(args[++i], 10);
                break;
            case '--timeout':
                opts.timeoutMs = parseInt(args[++i], 10);
                break;
            case '--start-server':
                opts.startServer = true;
                break;
            case '--server-port':
                opts.serverPort = parseInt(args[++i], 10);
                break;
        }
    }

    return opts;
}

// ── Dev server management ───────────────────────────────────────────

/**
 * Check if a port is accepting connections.
 */
function isPortOpen(port, host = '127.0.0.1') {
    return new Promise((resolve) => {
        const socket = new net.Socket();
        socket.setTimeout(1000);
        socket.on('connect', () => {
            socket.destroy();
            resolve(true);
        });
        socket.on('error', () => {
            socket.destroy();
            resolve(false);
        });
        socket.on('timeout', () => {
            socket.destroy();
            resolve(false);
        });
        socket.connect(port, host);
    });
}

/**
 * Wait for a port to become available, with timeout.
 */
async function waitForPort(port, timeoutMs = 30000) {
    const start = Date.now();
    const pollInterval = 500;

    while (Date.now() - start < timeoutMs) {
        if (await isPortOpen(port)) {
            return true;
        }
        await new Promise((r) => setTimeout(r, pollInterval));
    }

    return false;
}

/**
 * Start the dev server and wait for it to be ready.
 */
async function startDevServer(port, timeoutMs) {
    console.error(`[screenshot] Starting dev server on port ${port}...`);

    const server = spawn('npm', ['run', 'dev', '--', '--port', String(port)], {
        stdio: ['ignore', 'pipe', 'pipe'],
        detached: true,
    });

    server.stdout.on('data', (data) => {
        console.error(`[dev-server] ${data.toString().trim()}`);
    });

    server.stderr.on('data', (data) => {
        console.error(`[dev-server] ${data.toString().trim()}`);
    });

    const ready = await waitForPort(port, timeoutMs);

    if (!ready) {
        server.kill('SIGTERM');
        throw new Error(`Dev server did not start within ${timeoutMs}ms`);
    }

    console.error(`[screenshot] Dev server ready on port ${port}`);
    return server;
}

// ── Screenshot capture ──────────────────────────────────────────────

async function captureScreenshot(opts) {
    let devServer = null;

    try {
        // Start dev server if requested
        if (opts.startServer) {
            devServer = await startDevServer(opts.serverPort, opts.timeoutMs);
        }

        console.error(`[screenshot] Launching Chromium...`);

        const browser = await chromium.launch({
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
            ],
        });

        const context = await browser.newContext({
            viewport: { width: opts.width, height: opts.height },
            deviceScaleFactor: 2, // Retina quality
        });

        const page = await context.newPage();

        console.error(`[screenshot] Navigating to ${opts.url}...`);

        await page.goto(opts.url, {
            waitUntil: 'networkidle',
            timeout: opts.timeoutMs,
        });

        // Wait for any animations or lazy-loaded content
        if (opts.waitMs > 0) {
            console.error(`[screenshot] Waiting ${opts.waitMs}ms for page to settle...`);
            await page.waitForTimeout(opts.waitMs);
        }

        console.error(`[screenshot] Capturing screenshot...`);

        await page.screenshot({
            path: opts.outputPath,
            fullPage: opts.fullPage,
            type: 'png',
        });

        await browser.close();

        // Get file size
        const fileInfo = await stat(opts.outputPath);

        const result = {
            path: opts.outputPath,
            width: opts.width,
            height: opts.height,
            fullPage: opts.fullPage,
            bytes: fileInfo.size,
        };

        // Print result JSON to stdout for the caller to parse
        console.log(JSON.stringify(result));

        console.error(`[screenshot] Saved ${fileInfo.size} bytes to ${opts.outputPath}`);

        return result;
    } finally {
        // Clean up dev server
        if (devServer) {
            console.error('[screenshot] Stopping dev server...');
            process.kill(-devServer.pid, 'SIGTERM');
        }
    }
}

// ── Main ────────────────────────────────────────────────────────────

async function main() {
    const opts = parseArgs(process.argv);

    try {
        await captureScreenshot(opts);
    } catch (error) {
        console.error(`[screenshot] ERROR: ${error.message}`);
        process.exit(1);
    }
}

main();
