'use strict';
// Мост полосы с почтовыми ящиками (pages/shell.html): список ящиков с непрочитанными, переключение,
// добавление и меню ящика. Ничего больше странице полосы не доступно.
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('pochtaShell', {
    onState: (cb) => {
        ipcRenderer.on('shell:state', (_e, st) => cb(st));
        ipcRenderer.send('shell:ready');
    },
    switchTo: (id) => ipcRenderer.send('shell:switch', String(id)),
    add: () => ipcRenderer.send('shell:add'),
    menu: (id) => ipcRenderer.send('shell:menu', String(id)),
});
