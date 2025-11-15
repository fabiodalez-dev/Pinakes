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
