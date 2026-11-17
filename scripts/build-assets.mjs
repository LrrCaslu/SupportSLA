import { copyFileSync, mkdirSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const assets = join(root, 'public', 'assets');
if (!existsSync(assets)) mkdirSync(assets, { recursive: true });
console.log('Assets estáticos já estão prontos em public/assets.');
