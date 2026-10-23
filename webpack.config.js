const Path = require('path');
const { CssWebpackConfig, JavascriptWebpackConfig } = require('@silverstripe/webpack-config');

const PATHS = {
  ROOT: Path.resolve(),
};

const config = [
  new JavascriptWebpackConfig('js', PATHS)
    .setEntry({
      cms: `${PATHS.SRC}/js/cms.js`
    })
    .getConfig(),
  new CssWebpackConfig('css', PATHS)
    .setEntry({
      docs: `${PATHS.SRC}/styles/docs.scss`,
      cms: `${PATHS.SRC}/styles/cms.scss`,
    })
    .getConfig(),
];

// Use WEBPACK_CHILD=js or WEBPACK_CHILD=css env var to run a single config
module.exports = (process.env.WEBPACK_CHILD)
  ? config.find((entry) => entry.name === process.env.WEBPACK_CHILD)
  : module.exports = config;
