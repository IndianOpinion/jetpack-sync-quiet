# Pending workflow

`ci.yml` belongs in `.github/workflows/`. It sits here because the token that
pushed this branch could not write workflow files. Someone with the GitHub
`workflow` scope should move it:

```sh
git mv .github/workflows-pending/ci.yml .github/workflows/ci.yml
git rm .github/workflows-pending/README.md
```
