import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

declare const m: import('mithril').Static;

const K = 'ernestdefoe-manticore';

app.initializers.add(K, () => {
  // SearchModal is a lazy chunk: extend it by module path so core applies
  // this once the chunk arrives. The forum attribute lists only which
  // resources Manticore answers — no connection details reach the browser.
  extend(
    'flarum/common/components/SearchModal',
    'activeTabItems',
    function (this: any, items: ItemList<Mithril.Children>) {
      const source = this.activeSource?.();
      if (!source || !(app.forum.attribute<string[]>('manticoreSearch') || []).includes(source.resource)) return;

      items.add(
        'manticore',
        m('div.SearchModal-section.ManticoreBadge', [
          m('i.fas.fa-magnifying-glass', { 'aria-hidden': 'true' }),
          ' ',
          app.translator.trans(`${K}.forum.powered_by`),
        ]),
        0
      );
    }
  );
});
