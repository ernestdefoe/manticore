const config = require('flarum-webpack-config');

const c = config();

// Map `import m from 'mithril'` to Flarum's global mithril instance rather than
// bundling a second copy (which would break redraws).
c.externals = c.externals || [];
c.externals.push({ mithril: 'm' });

module.exports = c;
