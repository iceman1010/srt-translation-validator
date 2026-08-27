The goal of this project is to validate, translated subtitles. This should happen in PHP language using the following composer packages:

"mantas-done/subtitles"
"patrickschur/language-detection"


main code should be in form of a class file. And a file to run tests.

you can find a example subtitle in the folder 'examples'. Also there is a translation.


You have to do:

Create different variations of 'defect' subtitle files with the following defects:

- partial translation, where parts of the subtitle are in the original source language and stayed untranslated.
- invalid subtitle formats
- missing parts of the subtitle (are all captions translated from tyhe original)
- timestaps are not equal between orignal and translation

You need to document all you defects in 'docs/defect_examples.md'.

Next Task is to write the actual php class and run tests.



