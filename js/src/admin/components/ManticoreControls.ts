import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';

declare const m: import('mithril').Static;

const K = 'ernestdefoe-manticore';
// Core stores each resource's driver under `search_driver_<ModelClass>`.
const DRIVERS: [string, string][] = [
  ['search_driver_Flarum\\Discussion\\Discussion', 'use_for_discussions'],
  ['search_driver_Flarum\\User\\User', 'use_for_users'],
  ['search_driver_Flarum\\Post\\Post', 'use_for_posts'],
];
const t = (k: string, p: Record<string, string> = {}) => app.translator.trans(`${K}.admin.${k}`, p);

interface Attrs {
  setting: (k: string, d?: string) => (v?: string) => string;
}

export default class ManticoreControls extends Component<Attrs> {
  testing = false;
  status: { ok?: boolean; version?: string; error?: string } | null = null;
  rebuilding = false;

  view() {
    const setting = this.attrs.setting;
    const password = setting(`${K}.password`, '');
    const s = this.status;

    return m('div.ManticoreControls', [
      m('.Form-group', [
        m('label', t('password_label')),
        m('input.FormControl', {
          type: 'password',
          autocomplete: 'new-password',
          value: password(),
          oninput: (e: InputEvent) => password((e.target as HTMLInputElement).value),
        }),
      ]),
      m('hr'),
      m('.Form-group', [
        m('label', t('drivers_label')),
        m('.helpText', t('drivers_help')),
        DRIVERS.map(([key, label]) => {
          const driver = setting(key, 'default');
          return m(
            Switch,
            { state: driver() === 'manticore', onchange: (v: boolean) => driver(v ? 'manticore' : 'default') },
            t(label)
          );
        }),
      ]),
      m('hr'),
      m('.Form-group', [
        m('label', t('connection_label')),
        m('div', [
          m(Button, { className: 'Button', loading: this.testing, onclick: () => this.test() }, t('test_button')),
          ' ',
          s &&
            m(
              'span.ManticoreStatus',
              { className: s.ok ? 'ManticoreStatus--ok' : 'ManticoreStatus--fail' },
              s.ok
                ? t('test_ok', { version: s.version || '' })
                : s.error === 'not_configured'
                  ? t('not_configured')
                  : [t('test_fail'), s.error ? ` (${s.error})` : '']
            ),
        ]),
      ]),
      m('.Form-group', [
        m('label', t('rebuild_label')),
        m('.helpText', t('rebuild_help')),
        m(
          Button,
          { className: 'Button', loading: this.rebuilding, onclick: () => this.rebuild() },
          t('rebuild_button')
        ),
      ]),
    ]);
  }

  url(path: string): string {
    return app.forum.attribute('apiUrl') + path;
  }

  test() {
    this.testing = true;
    this.status = null;
    app
      .request<{ ok?: boolean; version?: string; error?: string }>({
        method: 'GET',
        url: this.url('/manticore/status'),
      })
      .then(
        (res) => (this.status = res),
        () => (this.status = { ok: false })
      )
      .then(() => {
        this.testing = false;
        m.redraw();
      });
  }

  rebuild() {
    this.rebuilding = true;
    app
      .request({ method: 'POST', url: this.url('/manticore/rebuild') })
      .then(
        () => app.alerts.show({ type: 'success' }, t('rebuild_queued')),
        () => app.alerts.show({ type: 'error' }, t('rebuild_failed'))
      )
      .then(() => {
        this.rebuilding = false;
        m.redraw();
      });
  }
}
