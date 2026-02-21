# AI Connector — Project Brief

I am a senior software developer for [easyhindityping.com](https://easyhindityping.com), [easynepalityping.com](https://easynepalityping.com), and [easymalayalamtyping.com](https://easymalayalamtyping.com).

## Objective

I would like to create a repository that would allow me to connect to various AI chat services (like OpenAI and Anthropic) by sending a payload with configuration parameters such as `temperature`, `agent`, and `max_tokens`, and to get the intended response in a JSON format.

The prompt can serve a variety of purposes. For example:

- I can send a prompt with a dictionary word in one language and expect its meaning, synonyms, antonyms, and examples in another language in a JSON format.
- I can send a sentence or paragraph in one language and expect a translation, transliteration, and transcription in another language in a JSON format.
- I can send a word to use in Scrabble and expect its description, synonyms, or antonyms.
- It can be a request to paraphrase a sentence, check grammar, or even run an AI detection check.

The possibilities are endless. The examples above are simply to indicate that this repository should support flexible input and output.

I can also get responses from different agents such as OpenAI and Anthropic, and use any model such as `gpt-5.1` or `gpt-3.5-turbo`.

It should also have a caching ability for similar queries, with an override parameter (such as `fresh`) that can be passed to bypass the cache.

The idea is to install this repository in any other project, and it will expose an interface to pass the payload with `model`, `message`, `temperature`, `API_KEY`, and other parameters.

---

## Reference Repository

I have a similar repository that handles translation by connecting to various services such as Google and Microsoft. Please read and research this repository:

```
/Users/sambhu/projects/sambhuWeb/Translator
```

Based on this, create the project on this project (folder) that allows flexible connections to — for now — OpenAI and Anthropic, and is extensible in the future to support other AI providers to get the expected output.

---

## Usage Context

The `/Users/sambhu/projects/sambhuWeb/Translator` repository is used by `/Users/sambhu/projects/sambhuWeb/EasyLanguageTyping` and is installed as a Composer package. The implementation is located at:

```
/Users/sambhu/projects/sambhuWeb/EasyLanguageTyping/Translate
```

---

## Scope

For this project, **focus only on creating the AIGateway repository** that allow sending request to the AI and getting a response. Example is just a guide illustrating how I intend to use it across multiple projects in the future to make AI requests.

Also create the unit and functional test.
