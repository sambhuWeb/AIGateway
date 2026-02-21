I am a senior software developer, for easyhindityping.com, easynepalityping.com easymalayalamtyping.com.

Objective:

I would like to create a repository that would allow me to connect to various Chat Ai (like open.ai and anthropic) by sending the payload with the  configuration such as (temperature, agent, max_tokens) and to get the intended response in a json format.

The prompt can be to do a variety of usage. For e.g.

I can send a prompt with a dictionary word in one language, and expect meaning, synonyms, antonyms, examples in another language in a json format.
I can send a sentence or paragraph in one language and expect translation, transliteration and transcription in another language in a json format.
I can send a word to search for a scrabble and expect its description, synonyms or antonyms.
It can be a request to paraphrase the sentence, check grammar or even check an AI detector.

The possibilities are endless. The examples above are just to indicate this repository should take input and output to be flexible.

I can also get the response from different agents such as open.ai, anthropic and the use of any model such as gpt-5.1, gpt-3.5-turbo.

It should also have a caching ability for the similar query with the overriding parameter that could be passed such as fresh.

Idea is to install this repo on any other repository and it will use its exposed interface to pass the payload with model, message, temperature, API_KEY and other parameters.

I have a similar repository that does the translation by connecting to various services such as Google and Microsoft. Read, research this repository: /Users/sambhu/projects/sambhuWeb/Translator and based on this create the AIConnector (give proper name) that allow the flexibility to connect for now (openai, and anthropic) and extendable in future to other AI to get expected output.

Further, /Users/sambhu/projects/sambhuWeb/Translator repository is used by /Users/sambhu/projects/sambhuWeb/EasyLanguageTyping and installed as a composer  package. The implementation is on /Users/sambhu/projects/sambhuWeb/EasyLanguageTyping/Translate.

For this project, focus only on creating this AIConnector repository. Another example is just a guide and how I intend to use it in future across multiple projects to make a request.
