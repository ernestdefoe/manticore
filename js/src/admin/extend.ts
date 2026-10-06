import app from 'flarum/admin/app';
import Admin from 'flarum/common/extenders/Admin';
import ManticoreControls from './components/ManticoreControls';

declare const m: import('mithril').Static;

const K = 'ernestdefoe-manticore';
const t = (k: string) => app.translator.trans(`${K}.admin.${k}`);

export default [
  new Admin()
    .setting(() => ({
      setting: `${K}.host`,
      type: 'text',
      label: t('host_label'),
      help: t('host_help'),
      placeholder: '127.0.0.1',
    }))
    .setting(() => ({
      setting: `${K}.port`,
      type: 'number',
      label: t('port_label'),
      placeholder: '9308',
    }))
    .setting(() => ({
      setting: `${K}.scheme`,
      type: 'select',
      options: { http: 'HTTP', https: 'HTTPS' },
      default: 'http',
      label: t('scheme_label'),
    }))
    .setting(() => ({
      setting: `${K}.table_prefix`,
      type: 'text',
      label: t('table_prefix_label'),
      help: t('table_prefix_help'),
    }))
    .setting(() => ({
      setting: `${K}.username`,
      type: 'text',
      label: t('username_label'),
      help: t('auth_help'),
    }))
    .customSetting(function (this: { setting: (k: string, d?: string) => (v?: string) => string }) {
      return m(ManticoreControls, { setting: (k: string, d?: string) => this.setting(k, d) });
    }),
];
