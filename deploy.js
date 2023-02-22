import FtpDeploy from 'ftp-deploy'
import * as dotenv from 'dotenv'
const deploy = new FtpDeploy();
dotenv.config({ path: '.env.local' });

const config = {
  user: process.env.FTP_USER,
  password: process.env.FTP_PASSWORD,
  host: process.env.FTP_HOST,
  port: process.env.FTP_PORT || 21,
  localRoot: 'docs/.vitepress/dist/',
  remoteRoot: '/',
  include: ['*', '**/*', '.*'],
  exclude: [
    'dist/**/*.map',
    'node_modules/**',
    'node_modules/**/.*',
    '.git/**',
  ],
  deleteRemote: true,
  forcePasv: true,
  sftp: false,
};

deploy
  .deploy(config)
  .then((res) => console.log('finished:', res))
  .catch((err) => console.log(err));
