import { autocompletion } from '@codemirror/autocomplete';
import type { Completion } from '@codemirror/autocomplete';
import { MySQL, PostgreSQL, sql } from '@codemirror/lang-sql';
import type { SQLNamespace } from '@codemirror/lang-sql';
import { StateField } from '@codemirror/state';
import type { EditorState, Extension } from '@codemirror/state';
import { Decoration, EditorView } from '@codemirror/view';
import type { DecorationSet } from '@codemirror/view';
import CodeMirror from '@uiw/react-codemirror';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { useMemo } from 'react';

export type SchemaColumn = {
    name: string;
    type: string | null;
    nullable: boolean | null;
};

export type SchemaTable = {
    name: string;
    columns: SchemaColumn[];
};

type Props = {
    value: string;
    onChange: (value: string) => void;
    driver?: string;
    tables?: SchemaTable[];
    height?: string;
    minHeight?: string;
    readOnly?: boolean;
    placeholder?: string;
    onEditorReady?: (view: EditorView) => void;
    onSelectionChange?: (selection: string) => void;
    onRunShortcut?: () => void;
};

function schemaFromTables(tables: SchemaTable[]): SQLNamespace {
    return Object.fromEntries(
        tables.map((table) => [
            table.name,
            table.columns.map((column): Completion => ({
                label: column.name,
                type: 'property',
                detail: column.type ?? undefined,
                info:
                    column.nullable === null
                        ? undefined
                        : column.nullable
                          ? 'Nullable'
                          : 'Required',
            })),
        ]),
    ) as SQLNamespace;
}

const selectedSqlDecoration = Decoration.mark({
    class: 'cm-crucible-selection',
});

function selectedSqlDecorations(state: EditorState): DecorationSet {
    const selection = state.selection.main;

    return selection.empty
        ? Decoration.none
        : Decoration.set([
              selectedSqlDecoration.range(selection.from, selection.to),
          ]);
}

const selectedSqlHighlight = StateField.define<DecorationSet>({
    create: selectedSqlDecorations,
    update: (decorations, transaction) => {
        if (!transaction.selection && !transaction.docChanged) {
            return decorations;
        }

        return selectedSqlDecorations(transaction.state);
    },
    provide: (field) => EditorView.decorations.from(field),
});

function editorTheme(): Extension {
    return EditorView.theme({
        '&': {
            backgroundColor: 'oklch(0.985 0.002 250)',
            color: 'oklch(0.21 0.006 250)',
            fontSize: '13px',
            maxWidth: '100%',
            overflow: 'hidden',
            width: '100%',
        },
        '.cm-scroller': {
            height: '100%',
            minHeight: '0',
            maxWidth: '100%',
            overflowX: 'auto',
            overflowY: 'auto',
            overscrollBehavior: 'contain',
        },
        '.cm-content': {
            fontFamily:
                'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace',
            padding: '14px 0',
            minWidth: '0',
        },
        '.cm-line': {
            padding: '0 16px',
            lineHeight: '1.65',
        },
        '.cm-placeholder': {
            color: 'oklch(0.6 0.006 250 / 0.72)',
        },
        '.cm-gutters': {
            backgroundColor: 'oklch(0.97 0.003 250)',
            borderRight: '1px solid oklch(0.88 0.004 250)',
            color: 'oklch(0.56 0.006 250)',
        },
        '.cm-activeLine': {
            backgroundColor: 'oklch(0.96 0.01 250)',
        },
        '.cm-activeLineGutter': {
            backgroundColor: 'oklch(0.94 0.01 250)',
            color: 'oklch(0.28 0.006 250)',
        },
        '& .cm-selectionBackground': {
            background: 'oklch(0.62 0.16 255 / 0.92) !important',
        },
        '&.cm-focused > .cm-scroller > .cm-selectionLayer .cm-selectionBackground':
            {
                background: 'oklch(0.62 0.16 255 / 0.92) !important',
            },
        '& ::selection': {
            backgroundColor: 'oklch(0.62 0.16 255 / 0.92) !important',
        },
        '.cm-crucible-selection': {
            backgroundColor: 'oklch(0.62 0.16 255 / 0.92)',
            color: 'oklch(0.99 0.002 250)',
        },
        '.cm-tooltip': {
            border: '1px solid oklch(0.84 0.006 250)',
            borderRadius: '6px',
            boxShadow: '0 12px 26px oklch(0.18 0.004 250 / 0.14)',
        },
        '.cm-tooltip-autocomplete ul': {
            fontFamily:
                'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace',
            fontSize: '12px',
        },
        '.cm-tooltip-autocomplete ul li[aria-selected]': {
            backgroundColor: 'oklch(0.62 0.18 255)',
            color: 'oklch(0.99 0.002 250)',
        },
        '&.cm-focused': {
            outline: 'none',
        },
    });
}

export function SqlEditor({
    value,
    onChange,
    driver,
    tables = [],
    height,
    minHeight = '18rem',
    readOnly = false,
    placeholder = 'select * from table_name',
    onEditorReady,
    onSelectionChange,
    onRunShortcut,
}: Props) {
    function handleKeyDownCapture(
        event: ReactKeyboardEvent<HTMLDivElement>,
    ): void {
        if (
            event.key !== 'Enter' ||
            (!event.metaKey && !event.ctrlKey) ||
            event.altKey
        ) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        onRunShortcut?.();
    }

    const extensions = useMemo(() => {
        const dialect = driver === 'mysql' ? MySQL : PostgreSQL;
        const schema = schemaFromTables(tables);

        return [
            sql({
                dialect,
                schema,
                upperCaseKeywords: true,
            }),
            autocompletion({
                activateOnTyping: true,
                activateOnTypingDelay: 100,
                maxRenderedOptions: 80,
            }),
            EditorView.lineWrapping,
            selectedSqlHighlight,
            EditorView.updateListener.of((viewUpdate) => {
                if (!viewUpdate.selectionSet) {
                    return;
                }

                const selection = viewUpdate.state.selection.main;

                onSelectionChange?.(
                    viewUpdate.state.sliceDoc(selection.from, selection.to),
                );
            }),
            editorTheme(),
        ];
    }, [driver, onSelectionChange, tables]);

    return (
        <CodeMirror
            value={value}
            height={height}
            minHeight={minHeight}
            className="h-full min-h-0"
            theme="light"
            basicSetup={{
                lineNumbers: true,
                foldGutter: false,
                highlightActiveLine: true,
                highlightActiveLineGutter: true,
                bracketMatching: true,
                closeBrackets: true,
                autocompletion: true,
                history: true,
            }}
            extensions={extensions}
            editable={!readOnly}
            readOnly={readOnly}
            placeholder={placeholder}
            indentWithTab={false}
            onChange={onChange}
            onCreateEditor={(view) => onEditorReady?.(view)}
            onKeyDownCapture={handleKeyDownCapture}
        />
    );
}
