{{--
    A single-file upload that already holds a file: the file sits on top.

    Mike, 25/9, on «Σχετικά με εμάς»: the × on the photo opened the file
    dialog, and picking a file from it showed old and new together for a
    moment and then lost both. Filament's stylesheet lifts FilePond's drop
    label to `z-index: 7` on every field, above the file list's 6, and a
    single-file field keeps the label live so a new file can replace the old
    one. With a file present the label lies across the top of the preview, so
    the ×, the edit button and the first 76px of the photo were all the label:
    a click opened the browser, and the pick became a replace racing the
    remove.

    Only where the field takes one file and has one: a multiple field lays its
    label out beside the grid, and an empty field's label is the button.
    FilePond's own stylesheet puts the label at 5; this is that, back.
--}}
<style>
    .filepond--root:has(.filepond--item):not(:has(input[type='file'][multiple])) .filepond--drop-label {
        z-index: 5;
    }
</style>
