const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = (_, argv = {}) => {
  const isProduction = argv.mode === 'production';

  return {
    mode: isProduction ? 'production' : 'development',
    devtool: isProduction ? false : 'source-map',
    context: __dirname,
    entry: {
      main: './js/index.js',
      vendor: './js/vendor.js',
      'copy-scanner': './js/copy-scanner.js',
    },
    module: {
      rules: [
        {
          test: /\.m?js$/,
          exclude: /node_modules/,
          use: {
            loader: 'babel-loader',
            options: { presets: ['@babel/preset-env'] },
          },
        },
        {
          // Emit the zxing-wasm binary as a same-origin asset under public/assets
          // so the library never fetches it from a CDN (blocked by connect-src 'self').
          test: /\.wasm$/,
          type: 'asset/resource',
        },
        {
          test: /\.css$/i,
          use: [
            MiniCssExtractPlugin.loader,
            'css-loader',
            'postcss-loader',  // Uses postcss.config.js (with tailwindcss + autoprefixer)
          ],
        },
      ],
    },
    plugins: [new MiniCssExtractPlugin({ filename: '[name].css' })],
    output: {
      filename: '[name].bundle.js',
      path: path.resolve(__dirname, '../public/assets'),
      // clean: {
      //   keep: /(swal-|csrf-helper\.js|sortable\.min\.(js|css))/  // Protect swal-*, csrf-helper.js, sortable.min.* from deletion
      // },
    },
    stats: 'errors-only',
  };
};
