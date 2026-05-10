import React from 'react';
import { fetchApi } from '../utils/api.js';

function getIcon(name, isDir) {
    if (isDir) return '📁';
    const ext = (name.split('.').pop() || '').toLowerCase();
    const icons = {php:'🐘',js:'🟨',css:'🎨',html:'🌐',json:'📋',md:'📝',png:'🖼️',jpg:'🖼️',svg:'🖼️'};
    return icons[ext] || '📄';
}
function fmtSize(b) { return b < 1024 ? b + ' B' : (b/1024).toFixed(1) + ' KB'; }

function FileNode({ node, depth=0, activeFile, attachedPaths, onFileClick, onLoadChildren, onAttach }) {
    const isDir = node.type === 'directory';
    const isOpen = node._open || false;
    const isActive = activeFile === node.path;
    const isAttached = attachedPaths && attachedPaths.includes(node.path);

    const click = () => {
        if(isDir) {
            if(!node._children && !node._loading) onLoadChildren(node);
            else { node._open = !node._open; onLoadChildren(node, true); }
        } else onFileClick(node);
    };

    const cls = `bc-tree__node ${isActive?'bc-tree__node--active':''} ${isAttached?'bc-tree__node--selected':''} ${isDir?'bc-tree__node--dir':''}`;

    return (
        <div>
            <div className={cls} style={{paddingLeft: (depth*16+8)+'px'}} onClick={click}>
                <span className={`bc-tree__arrow ${isOpen?'bc-tree__arrow--open':''}`}>{isDir ? '▶' : ''}</span>
                <span className="bc-tree__icon">{getIcon(node.name, isDir)}</span>
                <span className="bc-tree__name">{node.name}</span>
                {!isDir && node.size && <span className="bc-tree__size">{fmtSize(node.size)}</span>}
                <button className="bc-tree__attach" onClick={(e) => { e.stopPropagation(); onAttach(node); }} title="Attach">📎</button>
            </div>
            {isDir && isOpen && node._children && (
                <div className="bc-tree">
                    {node._children.map(n => <FileNode key={n.path} node={n} depth={depth+1} activeFile={activeFile} attachedPaths={attachedPaths} onFileClick={onFileClick} onLoadChildren={onLoadChildren} onAttach={onAttach} />)}
                </div>
            )}
        </div>
    );
}

export default function FileTree({ nodes, setFileTree, openFile, setOpenFile, setPanels, attachedFiles, setAttachedFiles }) {
    const onLoadChildren = (node, toggle) => {
        if(toggle) { setFileTree([...nodes]); return; }
        node._loading = true; setFileTree([...nodes]);
        fetchApi(`/files?path=${encodeURIComponent(node.path)}`).then(d => {
            node._children = Array.isArray(d) ? d : [];
            node._open = true; node._loading = false;
            setFileTree([...nodes]);
        }).catch(() => { node._loading = false; setFileTree([...nodes]); });
    };

    const onFileClick = (node) => {
        fetchApi(`/file/read?path=${encodeURIComponent(node.path)}`).then(d => {
            if(d && d.content !== undefined) {
                setOpenFile(d);
                setPanels(p => ({...p, editor: true}));
            }
        });
    };

    const onAttach = (node) => {
        if(!attachedFiles.some(f => f.path === node.path)) {
            setAttachedFiles(p => [...p, { path: node.path, name: node.name, type: node.type }]);
        }
    };

    return (
        <div className="bc-panel bc-panel--files">
            <div className="bc-panel__head">EXPLORER</div>
            <div className="bc-panel__body">
                {nodes.length === 0 ? <div className="bc-panel__empty">No paths configured.</div> : (
                    <div className="bc-tree">
                        {nodes.map(n => <FileNode key={n.path} node={n} activeFile={openFile?.path} attachedPaths={attachedFiles.filter(f=>f.path).map(f=>f.path)} onFileClick={onFileClick} onLoadChildren={onLoadChildren} onAttach={onAttach} />)}
                    </div>
                )}
            </div>
        </div>
    );
}