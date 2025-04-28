/**
 * Digital Signage Admin Scripts
 * 
 * Handles Gutenberg editor initialization and other admin functionality
 */

(function($) {
    'use strict';
    
    // Initialize Gutenberg Block Editors when DOM is ready
    $(document).ready(function() {
        // Initialize block editors if elements exist
        if (typeof wp !== 'undefined' && 
            typeof wp.element !== 'undefined' && 
            typeof wp.blocks !== 'undefined' && 
            typeof wp.blockEditor !== 'undefined') {
            // Wait for WordPress components to fully load
            setTimeout(initBlockEditors, 100);
        } else {
            console.error('WordPress editor components not loaded');
            $('.block-editor-container').html('<p>WordPress editor components could not be loaded. Please refresh the page and try again.</p>');
        }
        
        // Handle layout accordion toggle
        $('.digsign-layout-accordion-header').on('click', function() {
            $(this).parent().toggleClass('digsign-layout-accordion-open');
        });
        
        // Handle layout selection changes
        $('#digsign_layout_type').on('change', handleLayoutChange);
    });
    
    /**
     * Initialize Block Editors
     */
    function initBlockEditors() {
        console.log('Initializing block editors');
        
        // Initialize Header Content Editor if the container exists
        const headerContainer = document.getElementById('digsign-header-editor-container');
        if (headerContainer) {
            initEditor('digsign-header-editor-container', 'digsign_header_content', digsign_admin.header_content || '');
        }
        
        // Initialize Right Panel Editor if the container exists
        const rightPanelContainer = document.getElementById('digsign-right-panel-editor-container');
        if (rightPanelContainer) {
            initEditor('digsign-right-panel-editor-container', 'digsign_right_panel_content', digsign_admin.right_panel_content || '');
        }
    }
    
    /**
     * Initialize a specific Gutenberg Editor
     * 
     * @param {string} containerId The ID of the container element
     * @param {string} inputId The ID of the hidden input field to store content
     * @param {string} initialContent The initial content for the editor
     */
    function initEditor(containerId, inputId, initialContent) {
        const container = document.getElementById(containerId);
        if (!container) {
            console.error(`Container ${containerId} not found`);
            return;
        }
        
        try {
            const { registerCoreBlocks } = wp.blocks;
            const { render, createElement } = wp.element;
            const { BlockEditorProvider, BlockList, WritingFlow, BlockTools } = wp.blockEditor;
            const { SlotFillProvider, Popover } = wp.components;
            
            // Parse initial content into blocks
            let initialBlocks = [];
            if (initialContent && initialContent.trim() !== '') {
                try {
                    initialBlocks = wp.blocks.parse(initialContent);
                } catch (error) {
                    console.error('Error parsing content into blocks:', error);
                    initialBlocks = [];
                }
            }
            
            // Editor component
            const Editor = () => {
                const [blocks, updateBlocks] = wp.element.useState(initialBlocks);
                
                const onChange = (newBlocks) => {
                    updateBlocks(newBlocks);
                    // Update hidden input with serialized content
                    document.getElementById(inputId).value = wp.blocks.serialize(newBlocks);
                };
                
                return createElement(
                    SlotFillProvider,
                    null,
                    [
                        createElement(
                            BlockEditorProvider,
                            {
                                value: blocks,
                                onInput: onChange,
                                onChange: onChange,
                            },
                            createElement(
                                BlockTools,
                                null,
                                createElement(
                                    WritingFlow,
                                    null,
                                    createElement(BlockList, null)
                                )
                            )
                        ),
                        createElement(Popover.Slot, null)
                    ]
                );
            };
            
            // Clean the container before rendering
            container.innerHTML = '';
            
            // Render the editor
            render(createElement(Editor), container);
            console.log(`Editor ${containerId} initialized successfully`);
        } catch (error) {
            console.error(`Error initializing editor ${containerId}:`, error);
            container.innerHTML = '<p>Error initializing editor. Please check the browser console for details.</p>';
        }
    }
    
    /**
     * Handle layout change
     */
    function handleLayoutChange() {
        const layout = $(this).val();
        
        // Update layout preview
        $('.layout-preview-svg').hide();
        $(`#layout-preview-${layout}`).show();
        
        // Show/hide editors based on layout selection
        const headerEditor = $('#digsign-header-editor');
        const rightPanelEditor = $('#digsign-right-panel-editor');
        
        if (layout === 'fullscreen') {
            headerEditor.hide();
            rightPanelEditor.hide();
        } else if (layout === 'header-panels') {
            headerEditor.show();
            rightPanelEditor.show();
        } else if (layout === 'two-panels') {
            headerEditor.hide();
            rightPanelEditor.show();
        }
    }
    
    /**
     * Handle cleanup button click
     */
    function handleCleanup(e) {
        e.preventDefault();
        
        if (!confirm(digsign_admin.confirm_message)) {
            return;
        }
        
        const resultDiv = $('#digsign-cleanup-result');
        resultDiv.html('<p>' + digsign_admin.processing_message + '</p>').show();
        
        $.ajax({
            url: digsign_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'digsign_cleanup_thumbnails',
                nonce: digsign_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.html('<p>' + response.data + '</p>');
                } else {
                    resultDiv.html('<p class="error">' + (response.data || 'An error occurred.') + '</p>');
                }
            },
            error: function() {
                resultDiv.html('<p class="error">An error occurred during the cleanup process.</p>');
            }
        });
    }
})(jQuery);
