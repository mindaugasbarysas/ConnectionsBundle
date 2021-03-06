=============
Diff Provider
=============

Gets Client data needed to synchronize.

Client data source could be any data provider: DB, WS, etc.

Possible source providers
-------------------------

- `MySQL Binlog Diff Provider <binlog.rst>`_

Register your source settings into YAML configuration
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Binlog Diff Provider example:

.. code-block:: yml

     test.data_sync_source:
        class: ONGR\ConnectionsBundle\Event\DataSyncSourceEvent
        arguments:
            - @ongr_connections.sync.diff_provider.binlog_diff_provider
        tags:
            - { name: kernel.event_listener, event: ongr.pipeline.data_sync.some-target.source, method: onSource }
..